-- ForeverScribe: notes what the game shows you while you play, through the normal addon API only — what vendors sell,
-- what creatures and objects drop, who gives and takes quests, and where those creatures stand — so ForeverTracker
-- can add what your own server lacks. The notes are saved in WTF\Account\<account>\SavedVariables\ForeverScribe.lua
-- when you log out or /reload; ForeverTracker imports that file (The Forge › Imports, or by itself from the launcher).
-- Nothing personal is kept: no character, realm or account name.
--
-- It collects only on the official realms: a client connected to a private server (your own one included) uses
-- another portal than us / eu / kr / tw / cn, and nothing is noted there. /scribe on and /scribe off force it,
-- /scribe auto goes back to that rule. /scribe debug prints every note as it is taken.
--
-- What is noted: a vendor window, a loot window (and the corpse or chest it comes from), a quest dialog, talking to a
-- creature or an object, and a creature you target or point at within about 10 yards (its place is yours, so it has
-- to be close). Creatures seen from afar, portals and meeting stones you only click give the game nothing to read.
local ADDON = ...
local VERSION = 1
local OFFICIAL = { us = true, eu = true, kr = true, tw = true, cn = true }
local frame = CreateFrame("Frame")
local looted = {}          -- loot sources counted this session (a corpse opened twice counts once)
local reported = {}        -- errors already shown this session
local lastCast, lastCastAt = nil, 0

-- ---------------------------------------------------------------- the game API, old and new names
local function cvar(name)
    if C_CVar and C_CVar.GetCVar then return C_CVar.GetCVar(name) end
    return GetCVar and GetCVar(name)
end

local function spellName(spell)
    if C_Spell and C_Spell.GetSpellInfo then
        local info = C_Spell.GetSpellInfo(spell)
        return info and info.name
    end
    return GetSpellInfo and (GetSpellInfo(spell))
end

-- price, stack, available (-1: unlimited), extended cost
local function merchantItem(i)
    if C_MerchantFrame and C_MerchantFrame.GetItemInfo then
        local info = C_MerchantFrame.GetItemInfo(i)
        if info then return info.price, info.stackCount, info.numAvailable, info.hasExtendedCost end
        return
    end
    if GetMerchantItemInfo then
        local _, _, price, quantity, available, _, _, extended = GetMerchantItemInfo(i)
        return price, quantity, available, extended
    end
end

local function db()
    ForeverScribeDB = ForeverScribeDB or {}
    local d = ForeverScribeDB
    d.version = VERSION
    d.npcs = d.npcs or {}; d.objects = d.objects or {}; d.vendors = d.vendors or {}
    d.loot = d.loot or {}; d.quests = d.quests or {}
    return d
end

local function say(text) print("|cffd6a23eForeverScribe|r: " .. text) end
local function debug(text) if ForeverScribeDB and ForeverScribeDB.debug then say("|cff9d9d9d" .. text .. "|r") end end

local function official()
    local portal = (cvar("portal") or ""):lower()
    return OFFICIAL[portal] == true, portal
end

-- whether to note anything: forced on or off by /scribe, else only on the official realms
local function active()
    local mode = ForeverScribeDB and ForeverScribeDB.mode or "auto"
    if mode == "on" then return true end
    if mode == "off" then return false end
    return (official())
end

local function status()
    local mode = ForeverScribeDB and ForeverScribeDB.mode or "auto"
    local isOfficial, portal = official()
    if mode == "on" then return "|cff40ff40collecting|r (forced on: /scribe auto to go back to official realms only)" end
    if mode == "off" then return "|cffff6060paused|r (forced off: /scribe auto or /scribe on)" end
    return isOfficial and "|cff40ff40collecting|r (official realm)"
        or ("|cffff6060paused|r: not an official realm (portal \"" .. portal .. "\"); /scribe on to collect anyway")
end

-- "Creature-0-1-0-1-1234-0000ABCD" -> "npc", 1234; "GameObject-…" -> "go", entry
local function fromGUID(guid)
    if not guid then return end
    local kind, _, _, _, _, id = strsplit("-", guid)
    id = tonumber(id)
    if not id then return end
    if kind == "Creature" or kind == "Vehicle" then return "npc", id end
    if kind == "GameObject" then return "go", id end
end

-- where the player stands: world coordinates on open maps, or the zone map in percent
local function here()
    local y, x, _, map = UnitPosition("player")
    if x and map then return { m = map, x = math.floor(x * 10 + 0.5) / 10, y = math.floor(y * 10 + 0.5) / 10 } end
    local ui = C_Map and C_Map.GetBestMapForUnit and C_Map.GetBestMapForUnit("player")
    local pos = ui and C_Map.GetPlayerMapPosition(ui, "player")
    if pos then
        local px, py = pos:GetXY()
        return { ui = ui, px = math.floor(px * 10000 + 0.5) / 10000, py = math.floor(py * 10000 + 0.5) / 10000 }
    end
    local instance = select(8, GetInstanceInfo())
    if instance then return { m = instance, inside = true } end
end

-- one point per place: a new point closer than 10 yards to a known one is the same place
local function remember(kind, id, name)
    if not kind or not id then return end
    local list = kind == "npc" and db().npcs or db().objects
    local e = list[id] or { p = {} }
    list[id] = e
    if name and name ~= "" then e.n = name end
    local p = here()
    if not p then return end
    for _, q in ipairs(e.p) do
        if q.m and p.m and q.m == p.m and q.x and p.x and math.abs(q.x - p.x) < 10 and math.abs(q.y - p.y) < 10 then return end
        if q.ui and p.ui and q.ui == p.ui and math.abs(q.px - p.px) < 0.002 and math.abs(q.py - p.py) < 0.002 then return end
        if q.inside and p.inside and q.m == p.m then return end
    end
    if #e.p < 30 then
        table.insert(e.p, p)
        debug(("%s %s (%d) located"):format(kind == "npc" and "creature" or "object", name or e.n or "?", id))
    end
end

local function itemID(link)
    return link and tonumber(link:match("item:(%d+)"))
end

-- ---------------------------------------------------------------- vendors
local function scanMerchant()
    local kind, id = fromGUID(UnitGUID("npc"))
    if kind ~= "npc" then return end
    remember(kind, id, UnitName("npc"))
    local vendor = { items = {}, seen = time() }
    local count = 0
    for i = 1, GetMerchantNumItems() do
        local price, quantity, available, extended = merchantItem(i)
        local item = (GetMerchantItemID and GetMerchantItemID(i)) or itemID(GetMerchantItemLink(i))
        if item then
            local entry = { slot = i - 1, price = price or 0, stack = quantity or 1, limit = available or -1 }
            if extended and GetMerchantItemCostInfo then
                local costs = {}
                for c = 1, (GetMerchantItemCostInfo(i) or 0) do
                    local _, value, link, currency = GetMerchantItemCostItem(i, c)
                    local cost = itemID(link)
                    if cost then table.insert(costs, { item = cost, count = value })
                    elseif link then table.insert(costs, { currency = tonumber(link:match("currency:(%d+)")), count = value, name = currency }) end
                end
                if #costs > 0 then entry.cost = costs end
            end
            vendor.items[item] = entry
            count = count + 1
        end
    end
    if count > 0 then
        db().vendors[id] = vendor
        debug(("vendor %s (%d): %d item(s)"):format(UnitName("npc") or "?", id, count))
    end
end

-- ---------------------------------------------------------------- loot
local GATHERING = {}   -- localized names of the spells whose loot is not the creature's own drops
local function gathering()
    if next(GATHERING) then return GATHERING end
    for _, spell in ipairs({ 8613, 921, 2366, 2575, 7620 }) do
        local name = spellName(spell)
        if name then GATHERING[name] = spell == 921 and "pick" or "skin" end
    end
    return GATHERING
end

-- the corpse or chest of a loot slot: the slot's sources when the game gives them, else what you target
local function lootSources(slot)
    if GetLootSourceInfo then
        local sources = { GetLootSourceInfo(slot) }
        if #sources > 0 then return sources end
    end
    local guid = UnitGUID("target")
    if guid and UnitIsDead("target") then return { guid, 1 } end
    guid = UnitGUID("mouseover")
    if guid then return { guid, 1 } end
    return {}
end

local function scanLoot()
    if IsFishingLoot and IsFishingLoot() then return end
    local special = lastCast and GetTime() - lastCastAt < 3 and gathering()[lastCast]
    local counted, items = {}, 0
    for slot = 1, GetNumLootItems() do
        local slotType = GetLootSlotType and GetLootSlotType(slot) or 1
        local item = slotType == 1 and itemID(GetLootSlotLink(slot))
        local sources = lootSources(slot)
        for s = 1, #sources, 2 do
            local guid, quantity = sources[s], sources[s + 1] or 1
            local kind, id = fromGUID(guid)
            if kind then
                local key = (kind == "npc" and special or kind) .. ":" .. id
                local seenKey = guid .. ":" .. (special or "")
                if not looted[seenKey] or counted[seenKey] then
                    local l = db().loot[key] or { opened = 0, items = {} }
                    db().loot[key] = l
                    if not counted[seenKey] then
                        counted[seenKey] = true
                        l.opened = l.opened + 1
                        if kind == "go" then remember(kind, id) end
                    end
                    if item then
                        local i = l.items[item] or { n = 0 }
                        l.items[item] = i
                        i.n = i.n + 1
                        i.min = math.min(i.min or quantity, quantity)
                        i.max = math.max(i.max or quantity, quantity)
                        items = items + 1
                    end
                end
            end
        end
    end
    for key in pairs(counted) do looted[key] = true end
    if next(counted) then debug(("loot: %d item(s) from %d source(s)"):format(items, (function() local n = 0 for _ in pairs(counted) do n = n + 1 end return n end)())) end
end

-- ---------------------------------------------------------------- quests
local function questGiver(role)
    local quest = GetQuestID and GetQuestID()
    if not quest or quest == 0 then return end
    local kind, id = fromGUID(UnitGUID("questnpc") or UnitGUID("npc"))
    if not kind then return end
    remember(kind, id, UnitName("questnpc") or UnitName("npc"))
    local q = db().quests[quest] or {}
    db().quests[quest] = q
    q[role] = kind .. ":" .. id
    if role == "e" then
        local rewards = {}
        for i = 1, (GetNumQuestRewards and GetNumQuestRewards() or 0) do local r = itemID(GetQuestItemLink("reward", i)); if r then table.insert(rewards, r) end end
        for i = 1, (GetNumQuestChoices and GetNumQuestChoices() or 0) do local r = itemID(GetQuestItemLink("choice", i)); if r then table.insert(rewards, -r) end end
        if #rewards > 0 then q.r = rewards end
    end
    debug(("quest %d: %s %s:%d"):format(quest, role == "s" and "given by" or "taken by", kind, id))
end

-- ---------------------------------------------------------------- a creature close by (target, mouseover)
local function nearby(unit)
    if not UnitExists(unit) or UnitIsPlayer(unit) then return end
    local kind, id = fromGUID(UnitGUID(unit))
    if kind ~= "npc" then return end
    if InCombatLockdown() then return end
    local ok, close = pcall(CheckInteractDistance, unit, 3)   -- about 10 yards
    if ok and close then remember(kind, id, UnitName(unit)) end
end

-- ---------------------------------------------------------------- events
local handlers = {
    MERCHANT_SHOW = function() C_Timer.After(0.3, scanMerchant) end,
    LOOT_READY = scanLoot,
    LOOT_OPENED = scanLoot,
    QUEST_DETAIL = function() questGiver("s") end,
    QUEST_COMPLETE = function() questGiver("e") end,
    PLAYER_TARGET_CHANGED = function() nearby("target") end,
    UPDATE_MOUSEOVER_UNIT = function() nearby("mouseover") end,
    UNIT_SPELLCAST_SUCCEEDED = function(unit, _, spell)
        if unit == "player" and spell then lastCast, lastCastAt = spellName(spell), GetTime() end
    end,
}
for _, event in ipairs({ "GOSSIP_SHOW", "TRAINER_SHOW", "BANKFRAME_OPENED", "TAXIMAP_OPENED" }) do
    handlers[event] = function()
        local kind, id = fromGUID(UnitGUID("npc"))
        remember(kind, id, UnitName("npc"))
    end
end

frame:SetScript("OnEvent", function(_, event, ...)
    if event == "PLAYER_LOGIN" then
        say(status())
        if active() then
            local version, build = GetBuildInfo()
            local d = db()
            d.client = version .. "." .. build
            d.locale = GetLocale()
        end
        return
    end
    if not active() then return end
    -- a failing call is shown once (and kept in the notes) instead of silently losing everything after it
    local ok, err = pcall(handlers[event], ...)
    if not ok then
        err = tostring(err)
        if not reported[err] then
            reported[err] = true
            say("|cffff6060error|r in " .. event .. ": " .. err .. " — please report it with your ForeverScribe.lua")
            local d = db()
            d.errors = d.errors or {}
            if #d.errors < 20 then table.insert(d.errors, event .. ": " .. err) end
        end
    end
end)
frame:RegisterEvent("PLAYER_LOGIN")
for event in pairs(handlers) do pcall(frame.RegisterEvent, frame, event) end

SLASH_FOREVERSCRIBE1 = "/scribe"
SlashCmdList.FOREVERSCRIBE = function(msg)
    msg = (msg or ""):lower():gsub("^%s+", ""):gsub("%s+$", "")
    local d = db()
    if msg == "reset" then
        ForeverScribeDB = { mode = d.mode, debug = d.debug }
        say("notes cleared.")
        return
    end
    if msg == "on" or msg == "off" or msg == "auto" then
        d.mode = msg ~= "auto" and msg or nil
        say(status())
        return
    end
    if msg == "debug" then
        d.debug = not d.debug or nil
        say(d.debug and "debug on: every note is printed as it is taken." or "debug off.")
        return
    end
    local function count(t) local n = 0 for _ in pairs(t) do n = n + 1 end return n end
    say(status())
    say(("%d vendors, %d loot sources, %d quests, %d creatures and %d objects located. Saved when you log out or /reload."):format(
        count(d.vendors), count(d.loot), count(d.quests), count(d.npcs), count(d.objects)))
    say("talk to, buy from, loot or target (within ~10 yards) to note. /scribe on · off · auto · debug · reset")
end
