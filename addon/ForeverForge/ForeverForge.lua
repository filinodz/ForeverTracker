-- ForeverForge: places the creatures and world objects of a ForeverTracker spawn queue, for game masters.
--
-- Commands go through TrinityCore's addon command channel (prefix "TrinityCore"): every command is answered
-- with an acknowledgement, its messages and OK / failed, so each step waits for the server instead of guessing.
--   With the ForeverTracker core module (".forge version" answers): ".forge spawn" places each point from anywhere,
--   at the exact height the server computes, without moving the GM.
--   Without it: ".go xyz" to the point, then ".npc add" / ".gobject add" where the GM lands.
-- Bosses inside dungeons have no known position: stand where they belong and press "Spawn here".
--
-- /forge          open the window
-- /forge reset    forget which points were done

local PREFIX = "TrinityCore"
local ADDON = ...
local queue = ForeverForgeQueue or { points = {}, manual = {} }
local db
local state = { running = false, index = 0, module = nil, waiting = nil, pending = {}, counter = 0, lastMessages = {} }
local frame, ui = nil, {}

local function chat(text, r, g, b)
    DEFAULT_CHAT_FRAME:AddMessage("|cffd4a24cForeverForge|r " .. text, r or 1, g or 1, b or 1)
end

local function pointKey(p)
    return string.format("%s:%d:%.1f:%.1f", p.k, p.e, p.x, p.y)
end

local function num(value)
    local text = string.format("%.2f", value):gsub("0+$", ""):gsub("%.$", "")
    return text
end

-- ---------------------------------------------------------------- command channel
-- send(command, callback(ok, messages)): command without the leading dot
local function send(command, callback)
    state.counter = (state.counter + 1) % 10000
    local echo = string.format("%04d", state.counter)
    state.pending[echo] = { callback = callback, messages = {} }
    C_ChatInfo.SendAddonMessage(PREFIX, "i" .. echo .. command, "WHISPER", UnitName("player"))
    C_Timer.After(20, function()
        local entry = state.pending[echo]
        if entry then
            state.pending[echo] = nil
            entry.callback(false, { "no answer from the server (is the AddonChannel enabled and are you a GM?)" })
        end
    end)
end

local events = CreateFrame("Frame")
events:RegisterEvent("ADDON_LOADED")
events:RegisterEvent("CHAT_MSG_ADDON")
events:RegisterEvent("PLAYER_ENTERING_WORLD")
events:SetScript("OnEvent", function(_, event, ...)
    if event == "ADDON_LOADED" and ... == ADDON then
        ForeverForgeDB = ForeverForgeDB or { done = {}, log = {} }
        db = ForeverForgeDB
        db.done, db.log = db.done or {}, db.log or {}
        C_ChatInfo.RegisterAddonMessagePrefix(PREFIX)
    elseif event == "CHAT_MSG_ADDON" then
        local prefix, text = ...
        if prefix ~= PREFIX or not text or #text < 5 then return end
        local kind, echo, body = text:sub(1, 1), text:sub(2, 5), text:sub(6)
        local entry = state.pending[echo]
        if not entry then return end
        if kind == "m" then
            table.insert(entry.messages, body)
        elseif kind == "o" or kind == "f" then
            state.pending[echo] = nil
            entry.callback(kind == "o", entry.messages)
        end
    elseif event == "PLAYER_ENTERING_WORLD" then
        state.loadedAt = GetTime()
        if frame and frame:IsShown() then ui.refreshManual() end
    end
end)

-- ---------------------------------------------------------------- the queue
local function remaining()
    local n = 0
    for _, p in ipairs(queue.points or {}) do
        if not db.done[pointKey(p)] then n = n + 1 end
    end
    return n
end

local function nextPoint()
    local points = queue.points or {}
    for i = state.index + 1, #points do
        if not db.done[pointKey(points[i])] then
            state.index = i
            return points[i]
        end
    end
    return nil
end

local function finish(p, ok, text)
    if ok then
        db.done[pointKey(p)] = true
        table.insert(db.log, { t = time(), k = p.k, e = p.e, m = p.m, x = p.x, y = p.y, r = text })
    else
        chat("|cffff6060" .. p.n .. " (" .. p.e .. "): " .. (text or "failed") .. "|r")
    end
    ui.refresh()
end

local step

local function after(seconds, fn)
    C_Timer.After(seconds, function() if state.running then fn() end end)
end

local function spawnWithModule(p)
    local command = string.format("forge spawn %s %d %d %s %s", p.k, p.e, p.m, num(p.x), num(p.y))
    if p.z then
        command = command .. " " .. num(p.z) .. " " .. num(p.o or 0)
        if p.k == "npc" and (p.w or 0) > 0 then command = command .. " " .. num(p.w) end
    end
    send(command, function(ok, messages)
        local text = table.concat(messages, " ")
        finish(p, ok and text:find("FORGE OK") ~= nil, text)
        after(0.4, step)
    end)
end

local function spawnByTravel(p)
    local add = p.k == "npc" and ("npc add " .. p.e) or ("gobject add " .. p.e)
    local function place()
        send(add, function(ok, messages)
            finish(p, ok, table.concat(messages, " "))
            after(0.8, step)
        end)
    end
    -- ".go xyz x y z map": a z is required before the map; without a known height the GM is dropped from
    -- above and a second ".go xyz x y" on the same map puts them on the ground
    local z = p.z and num(p.z + 1.5) or "500"
    send(string.format("go xyz %s %s %s %d %s", num(p.x), num(p.y), z, p.m, num(p.o or 0)), function(ok, messages)
        if not ok then
            finish(p, false, table.concat(messages, " "))
            return after(0.5, step)
        end
        after(2.5, function()
            if p.z then return place() end
            send(string.format("go xyz %s %s", num(p.x), num(p.y)), function()
                after(1.5, place)
            end)
        end)
    end)
end

step = function()
    if not state.running then return end
    local p = nextPoint()
    if not p then
        state.running = false
        chat("queue finished: " .. remaining() .. " point(s) left.")
        ui.refresh()
        return
    end
    state.current = p
    ui.refresh()
    if state.module then spawnWithModule(p) else spawnByTravel(p) end
end

local function start()
    if #(queue.points or {}) == 0 then
        return chat("the queue is empty: export one from the website (The Forge > Spawns > In-game addon), then /reload.")
    end
    state.running, state.index = true, 0
    ui.refresh()
    if state.module ~= nil then return step() end
    send("forge version", function(ok, messages)
        state.module = ok and table.concat(messages, " "):find("FORGE OK version") ~= nil
        chat(state.module and "core module found: spawning in place, without travelling."
            or "no core module: travelling to each point with .go xyz.")
        step()
    end)
end

-- ---------------------------------------------------------------- window
local function button(parent, text, width, onClick)
    local b = CreateFrame("Button", nil, parent, "UIPanelButtonTemplate")
    b:SetSize(width, 22)
    b:SetText(text)
    b:SetScript("OnClick", onClick)
    return b
end

local function currentMap()
    local _, _, _, _, _, _, _, mapId = GetInstanceInfo()
    return mapId
end

local function build()
    frame = CreateFrame("Frame", "ForeverForgeFrame", UIParent, "BasicFrameTemplateWithInset")
    frame:SetSize(420, 380)
    frame:SetPoint("CENTER")
    frame:SetMovable(true)
    frame:EnableMouse(true)
    frame:RegisterForDrag("LeftButton")
    frame:SetScript("OnDragStart", frame.StartMoving)
    frame:SetScript("OnDragStop", frame.StopMovingOrSizing)
    frame.TitleText = frame.TitleText or frame:CreateFontString(nil, "OVERLAY", "GameFontHighlight")
    frame.TitleText:SetText("ForeverForge")
    frame.TitleText:SetPoint("TOP", 0, -5)

    ui.status = frame:CreateFontString(nil, "OVERLAY", "GameFontNormal")
    ui.status:SetPoint("TOPLEFT", 16, -34)
    ui.status:SetWidth(388)
    ui.status:SetJustifyH("LEFT")

    ui.current = frame:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
    ui.current:SetPoint("TOPLEFT", ui.status, "BOTTOMLEFT", 0, -6)
    ui.current:SetWidth(388)
    ui.current:SetJustifyH("LEFT")

    ui.start = button(frame, "Start", 90, function() if state.running then state.running = false; ui.refresh() else start() end end)
    ui.start:SetPoint("TOPLEFT", ui.current, "BOTTOMLEFT", 0, -10)
    ui.skip = button(frame, "Skip", 70, function()
        if state.current then db.done[pointKey(state.current)] = true; ui.refresh() end
    end)
    ui.skip:SetPoint("LEFT", ui.start, "RIGHT", 6, 0)
    ui.go = button(frame, "Go to next", 100, function()
        local p = nextPoint()
        state.index = math.max(0, state.index - 1)
        if p then send(string.format("go xyz %s %s %s %d", num(p.x), num(p.y), p.z and num(p.z + 1.5) or "500", p.m), function() end) end
    end)
    ui.go:SetPoint("LEFT", ui.skip, "RIGHT", 6, 0)

    local title = frame:CreateFontString(nil, "OVERLAY", "GameFontNormal")
    title:SetPoint("TOPLEFT", ui.start, "BOTTOMLEFT", 0, -16)
    title:SetText("To place by hand in this instance")
    ui.manualHint = frame:CreateFontString(nil, "OVERLAY", "GameFontDisableSmall")
    ui.manualHint:SetPoint("TOPLEFT", title, "BOTTOMLEFT", 0, -4)
    ui.manualHint:SetWidth(388)
    ui.manualHint:SetJustifyH("LEFT")
    ui.rows = {}
    for i = 1, 8 do
        local row = CreateFrame("Frame", nil, frame)
        row:SetSize(388, 22)
        row:SetPoint("TOPLEFT", ui.manualHint, "BOTTOMLEFT", 0, -4 - (i - 1) * 24)
        row.text = row:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
        row.text:SetPoint("LEFT")
        row.text:SetWidth(270)
        row.text:SetJustifyH("LEFT")
        row.button = button(row, "Spawn here", 100, function()
            if not row.item then return end
            local m = row.item
            send(m.k == "npc" and ("npc add " .. m.e) or ("gobject add " .. m.e), function(ok, messages)
                if ok then db.done["manual:" .. m.k .. ":" .. m.e] = true end
                chat((ok and "spawned " or "|cffff6060failed|r ") .. m.n .. " " .. table.concat(messages, " "))
                ui.refreshManual()
            end)
        end)
        row.button:SetPoint("RIGHT")
        ui.rows[i] = row
    end
    ui.refreshManual()
end

function ui.refresh()
    if not frame then return end
    local total = #(queue.points or {})
    local left = remaining()
    ui.status:SetText(string.format("%d / %d point(s) placed%s", total - left, total, queue.generated ~= "" and ("  ·  queue of " .. (queue.generated or ""):sub(1, 16)) or ""))
    local p = state.current
    ui.current:SetText(state.running and p and string.format("%s %s (%d) · map %d · %s %s", p.k == "npc" and "Creature" or "Object", p.n, p.e, p.m, num(p.x), num(p.y))
        or (state.running and "" or "Stand anywhere as a GM (.gm on) and press Start."))
    ui.start:SetText(state.running and "Pause" or "Start")
end

function ui.refreshManual()
    if not frame then return end
    local map, shown = currentMap(), 0
    for _, m in ipairs(queue.manual or {}) do
        if m.m == map and not db.done["manual:" .. m.k .. ":" .. m.e] and shown < #ui.rows then
            shown = shown + 1
            ui.rows[shown].item = m
            ui.rows[shown].text:SetText(m.n .. " (" .. m.e .. ")")
            ui.rows[shown]:Show()
        end
    end
    for i = shown + 1, #ui.rows do ui.rows[i]:Hide(); ui.rows[i].item = nil end
    local total = 0
    for _, m in ipairs(queue.manual or {}) do if not db.done["manual:" .. m.k .. ":" .. m.e] then total = total + 1 end end
    ui.manualHint:SetText(shown > 0 and "Stand where each one belongs, face the right way, then press Spawn here."
        or string.format("Nothing here. %d to place in other dungeons and raids: enter one to see its list.", total))
end

SLASH_FOREVERFORGE1 = "/forge"
SLASH_FOREVERFORGE2 = "/ff"
SlashCmdList.FOREVERFORGE = function(arg)
    if not db then return end
    if arg == "reset" then
        db.done = {}
        chat("progress forgotten.")
    end
    if not frame then build() end
    frame:Show()
    ui.refresh()
    ui.refreshManual()
end
