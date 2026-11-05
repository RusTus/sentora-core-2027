--[[
Sentora: what a hosted site's request may be handed to (mod_lua, LuaHookFixups).

A site's .htaccess can choose a request's handler (SetHandler, AddHandler, RewriteRule
[H=...] or [P]). Without this, a site could hand its own PHP file to the panel's PHP
pool (running it as the panel's user), to another account's pool (PHP-FPM keeps
settings sent with a request in that worker, so a site could plant settings in
someone else's), to server-status or server-info (the whole server's live requests),
or proxy requests to services on this server.

This runs after .htaccess and mod_rewrite have chosen the handler. For requests of a
hosted site (its DocumentRoot is in the hosting folder) it allows a proxy handler only
to that account's own PHP-FPM sockets, and refuses the status handlers and proxying.
The account ids come from sentora-accounts.map, written by the Sentora daemon.
]]

local MAP = "/etc/sentora/configs/apache/sentora-accounts.map"
local cache = { mtime = -1, hosted = nil, ids = {} }

local function load_map(r)
    local st = r:stat(MAP)
    if not st then return false end
    if st.mtime == cache.mtime then return true end
    local f = io.open(MAP, "r")
    if not f then return false end
    local ids, hosted = {}, nil
    for line in f:lines() do
        local key, value = line:match("^(%S+)%s+(%S+)$")
        if key == "hosted_dir" then
            hosted = value
        elseif key and value and value:match("^%d+$") then
            ids[key] = value
        end
    end
    f:close()
    cache = { mtime = st.mtime, hosted = hosted, ids = ids }
    return hosted ~= nil
end

function sentora_guard(r)
    if not load_map(r) then
        return apache2.DECLINED -- no map yet (the daemon writes it within 5 minutes)
    end
    local root = r.document_root or ""
    local hosted = cache.hosted
    if root:sub(1, #hosted) ~= hosted then
        return apache2.DECLINED -- not a hosted site (the panel, webmail, static pages)
    end
    local handler = r.handler or ""
    local filename = r.filename or ""
    if handler == "server-status" or handler == "server-info" or handler == "proxy-server"
        or filename:sub(1, 6) == "proxy:" then
        r:warn("Sentora: refused handler '" .. handler .. "' for a hosted site: " .. (r.uri or ""))
        return 403
    end
    if handler:sub(1, 6) == "proxy:" then
        local account = root:sub(#hosted + 1):match("^([^/]+)/")
        local id = account and cache.ids[account]
        local sock = handler:match("^proxy:unix:/run/php/web(%d+)%.sock|")
            or handler:match("^proxy:unix:/run/sentora%-php/web(%d+)%.sock|")
        if not id or sock ~= id then
            r:warn("Sentora: refused PHP handler '" .. handler .. "' for account " .. tostring(account) .. ": " .. (r.uri or ""))
            return 403
        end
    end
    return apache2.DECLINED
end


--[[
Output filter for hosted sites (LuaOutputFilter SENTORA_STATUS_BLOCK): mod_rewrite's
[H=] sets a handler after the check above, so a status page is blanked here, when the
content is made and the handler is final. Everything else passes through unchanged.
]]
function sentora_status_block(r)
    local h = r.handler or ""
    if h ~= "server-status" and h ~= "server-info" then
        coroutine.yield()
        while bucket ~= nil do
            coroutine.yield(bucket)
        end
        return
    end
    r.status = 403
    r.content_type = "text/plain"
    r:warn("Sentora: status page refused for a hosted site: " .. (r.uri or ""))
    coroutine.yield("403 Forbidden\n")
    while bucket ~= nil do
        coroutine.yield("")
    end
end
