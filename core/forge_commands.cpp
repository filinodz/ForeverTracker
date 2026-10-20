/*
 * ForeverTracker · Forge commands · by FilinoDZ (MIT, part of https://github.com/filinodz/ForeverTracker)
 *
 * Spawns creatures and gameobjects at world coordinates without a player standing there, so the ForeverTracker
 * website (through the remote console) or its in-game addon can place what the server lacks. The height is
 * computed by the server itself from its maps and vmaps, buildings included: a z given with the command is only
 * a hint (the floor closest under it is used).
 *
 *   .forge version
 *   .forge height <map> <x> <y> [zHint]
 *   .forge spawn npc <entry> <map> <x> <y> [zHint] [orientation] [wanderDistance]
 *   .forge spawn go  <entry> <map> <x> <y> [zHint] [orientation]
 *   .forge delete npc <guid>
 *   .forge delete go  <guid>
 *
 * Every answer starts with "FORGE OK" or "FORGE ERR" so tools can read it. Spawns are saved in the world
 * database exactly like .npc add / .gobject add and appear at once where the grid is loaded.
 */

#include "ScriptMgr.h"
#include "Chat.h"
#include "ChatCommand.h"
#include "Creature.h"
#include "DatabaseEnv.h"
#include "DB2Stores.h"
#include "GameObject.h"
#include "GridDefines.h"
#include "Map.h"
#include "MapManager.h"
#include "MovementDefines.h"
#include "ObjectMgr.h"
#include "PhasingHandler.h"
#include "RBAC.h"
#include "StringFormat.h"
#include "TerrainMgr.h"
#include <algorithm>

using namespace Trinity::ChatCommands;

namespace
{
    constexpr uint32 FORGE_COMMANDS_VERSION = 1;

    bool Answer(ChatHandler* handler, bool ok, std::string const& text)
    {
        handler->SendSysMessage(std::string(ok ? "FORGE OK " : "FORGE ERR ") + text);
        if (!ok)
            handler->SetSentErrorMessage(true);
        return ok;
    }

    /// Ground height at x, y: the floor under the hint when there is one, else the highest surface (as .go xyz).
    Optional<float> GroundHeight(uint32 mapId, float x, float y, Optional<float> hint)
    {
        std::shared_ptr<TerrainInfo> terrain = sTerrainMgr.LoadTerrain(mapId);
        if (!terrain)
            return {};
        PhaseShift const& phaseShift = PhasingHandler::GetEmptyPhaseShift();
        float z = INVALID_HEIGHT;
        if (hint)
        {
            z = terrain->GetStaticHeight(phaseShift, mapId, x, y, *hint + 3.0f, true, 40.0f);
            if (z <= INVALID_HEIGHT)
                z = *hint;
        }
        else
            z = std::max(terrain->GetStaticHeight(phaseShift, mapId, x, y, MAX_HEIGHT), terrain->GetWaterLevel(phaseShift, mapId, x, y));
        if (z <= INVALID_HEIGHT)
            return {};
        return z;
    }

    /// Every difficulty the map has (none for open world maps without MapDifficulty rows).
    std::vector<Difficulty> SpawnDifficulties(uint32 mapId)
    {
        std::vector<Difficulty> difficulties;
        for (MapDifficultyEntry const* entry : sMapDifficultyStore)
            if (entry->MapID == mapId)
                difficulties.push_back(Difficulty(entry->DifficultyID));
        if (difficulties.empty())
            difficulties.push_back(DIFFICULTY_NONE);
        std::sort(difficulties.begin(), difficulties.end());
        difficulties.erase(std::unique(difficulties.begin(), difficulties.end()), difficulties.end());
        return difficulties;
    }

    std::string DifficultyList(std::vector<Difficulty> const& difficulties)
    {
        std::string out;
        for (Difficulty difficulty : difficulties)
            out += (out.empty() ? "" : ",") + std::to_string(int32(difficulty));
        return out;
    }

    bool CheckPlace(ChatHandler* handler, uint32 mapId, float x, float y)
    {
        if (!sMapStore.LookupEntry(mapId) || !MapManager::IsValidMapCoord(mapId, x, y))
            return Answer(handler, false, Trinity::StringFormat("invalid place map {} x {} y {}", mapId, x, y));
        if (sObjectMgr->IsTransportMap(mapId))
            return Answer(handler, false, Trinity::StringFormat("map {} is a transport", mapId));
        return true;
    }
}

class forge_commandscript : public CommandScript
{
public:
    forge_commandscript() : CommandScript("forge_commandscript") { }

    std::span<ChatCommandBuilder const> GetCommands() const override
    {
        static ChatCommandTable forgeSpawnTable =
        {
            { "npc",     HandleSpawnCreature,   rbac::RBAC_PERM_COMMAND_NPC_ADD,        Console::Yes },
            { "go",      HandleSpawnGameObject, rbac::RBAC_PERM_COMMAND_GOBJECT_ADD,    Console::Yes },
        };
        static ChatCommandTable forgeDeleteTable =
        {
            { "npc",     HandleDeleteCreature,   rbac::RBAC_PERM_COMMAND_NPC_DELETE,     Console::Yes },
            { "go",      HandleDeleteGameObject, rbac::RBAC_PERM_COMMAND_GOBJECT_DELETE, Console::Yes },
        };
        static ChatCommandTable forgeTable =
        {
            { "version", HandleVersion,         rbac::RBAC_PERM_COMMAND_NPC_ADD,        Console::Yes },
            { "height",  HandleHeight,          rbac::RBAC_PERM_COMMAND_NPC_ADD,        Console::Yes },
            { "spawn",   forgeSpawnTable },
            { "delete",  forgeDeleteTable },
        };
        static ChatCommandTable commandTable =
        {
            { "forge", forgeTable },
        };
        return commandTable;
    }

    static bool HandleVersion(ChatHandler* handler)
    {
        return Answer(handler, true, Trinity::StringFormat("version {}", FORGE_COMMANDS_VERSION));
    }

    static bool HandleHeight(ChatHandler* handler, uint32 mapId, float x, float y, Optional<float> hint)
    {
        if (!CheckPlace(handler, mapId, x, y))
            return false;
        Optional<float> z = GroundHeight(mapId, x, y, hint);
        if (!z)
            return Answer(handler, false, "no ground here (are the maps and vmaps extracted?)");
        return Answer(handler, true, Trinity::StringFormat("height {:.2f}", *z));
    }

    static bool HandleSpawnCreature(ChatHandler* handler, uint32 entry, uint32 mapId, float x, float y, Optional<float> hint, Optional<float> orientation, Optional<float> wander)
    {
        if (!sObjectMgr->GetCreatureTemplate(entry))
            return Answer(handler, false, Trinity::StringFormat("creature_template {} does not exist", entry));
        if (!CheckPlace(handler, mapId, x, y))
            return false;
        Optional<float> z = GroundHeight(mapId, x, y, hint);
        if (!z)
            return Answer(handler, false, "no ground here (are the maps and vmaps extracted?)");

        float o = Position::NormalizeOrientation(orientation.value_or(0.0f));
        float wanderDistance = std::max(0.0f, wander.value_or(0.0f));
        uint8 movementType = wanderDistance > 0.0f ? uint8(RANDOM_MOTION_TYPE) : uint8(IDLE_MOTION_TYPE);
        int8 equipment = 1;
        int8 equipmentId = sObjectMgr->GetEquipmentInfo(entry, equipment) ? 1 : 0;
        std::vector<Difficulty> difficulties = SpawnDifficulties(mapId);
        uint32 respawn = 120;

        ObjectGuid::LowType spawnId = sObjectMgr->GenerateCreatureSpawnId();
        WorldDatabase.DirectExecute(Trinity::StringFormat(
            "INSERT INTO creature (guid, id, map, spawnDifficulties, equipment_id, position_x, position_y, position_z, orientation, spawntimesecs, wander_distance, MovementType) "
            "VALUES ({}, {}, {}, '{}', {}, {}, {}, {}, {}, {}, {}, {})",
            spawnId, entry, mapId, DifficultyList(difficulties), equipmentId, x, y, *z, o, respawn, wanderDistance, movementType).c_str());

        CreatureData& data = sObjectMgr->NewOrExistCreatureData(spawnId);
        data.spawnId = spawnId;
        data.id = entry;
        data.mapId = mapId;
        data.spawnPoint.Relocate(x, y, *z, o);
        data.equipmentId = equipmentId;
        data.spawntimesecs = respawn;
        data.wander_distance = wanderDistance;
        data.movementType = movementType;
        data.spawnDifficulties = difficulties;
        data.spawnGroupData = sObjectMgr->GetDefaultSpawnGroup();
        sObjectMgr->AddCreatureToGrid(&data);

        // visible at once where players already are; elsewhere it appears when its grid loads
        if (Map* map = sMapMgr->FindMap(mapId, 0))
            if (map->IsGridLoaded(x, y))
                Creature::CreateCreatureFromDB(spawnId, map, true, true);

        return Answer(handler, true, Trinity::StringFormat("npc {} {} {} {:.2f} {:.2f} {:.2f}", spawnId, entry, mapId, x, y, *z));
    }

    static bool HandleSpawnGameObject(ChatHandler* handler, uint32 entry, uint32 mapId, float x, float y, Optional<float> hint, Optional<float> orientation)
    {
        GameObjectTemplate const* info = sObjectMgr->GetGameObjectTemplate(entry);
        if (!info)
            return Answer(handler, false, Trinity::StringFormat("gameobject_template {} does not exist", entry));
        if (info->displayId && !sGameObjectDisplayInfoStore.LookupEntry(info->displayId))
            return Answer(handler, false, Trinity::StringFormat("gameobject_template {} has an invalid displayId {}", entry, info->displayId));
        if (!CheckPlace(handler, mapId, x, y))
            return false;
        Optional<float> z = GroundHeight(mapId, x, y, hint);
        if (!z)
            return Answer(handler, false, "no ground here (are the maps and vmaps extracted?)");

        float o = Position::NormalizeOrientation(orientation.value_or(0.0f));
        QuaternionData rotation = QuaternionData::fromEulerAnglesZYX(o, 0.0f, 0.0f);
        std::vector<Difficulty> difficulties = SpawnDifficulties(mapId);
        int32 respawn = 300;

        ObjectGuid::LowType spawnId = sObjectMgr->GenerateGameObjectSpawnId();
        WorldDatabase.DirectExecute(Trinity::StringFormat(
            "INSERT INTO gameobject (guid, id, map, spawnDifficulties, position_x, position_y, position_z, orientation, rotation0, rotation1, rotation2, rotation3, spawntimesecs, animprogress, state) "
            "VALUES ({}, {}, {}, '{}', {}, {}, {}, {}, {}, {}, {}, {}, {}, 255, 1)",
            spawnId, entry, mapId, DifficultyList(difficulties), x, y, *z, o, rotation.x, rotation.y, rotation.z, rotation.w, respawn).c_str());

        GameObjectData& data = sObjectMgr->NewOrExistGameObjectData(spawnId);
        data.spawnId = spawnId;
        data.id = entry;
        data.mapId = mapId;
        data.spawnPoint.Relocate(x, y, *z, o);
        data.rotation = rotation;
        data.spawntimesecs = respawn;
        data.animprogress = 255;
        data.goState = GO_STATE_READY;
        data.spawnDifficulties = difficulties;
        data.spawnGroupData = sObjectMgr->GetDefaultSpawnGroup();
        sObjectMgr->AddGameobjectToGrid(&data);

        if (Map* map = sMapMgr->FindMap(mapId, 0))
            if (map->IsGridLoaded(x, y))
                GameObject::CreateGameObjectFromDB(spawnId, map);

        return Answer(handler, true, Trinity::StringFormat("go {} {} {} {:.2f} {:.2f} {:.2f}", spawnId, entry, mapId, x, y, *z));
    }

    static bool HandleDeleteCreature(ChatHandler* handler, uint64 spawnId)
    {
        if (!Creature::DeleteFromDB(spawnId))
            return Answer(handler, false, Trinity::StringFormat("no creature spawn {}", spawnId));
        return Answer(handler, true, Trinity::StringFormat("deleted npc {}", spawnId));
    }

    static bool HandleDeleteGameObject(ChatHandler* handler, uint64 spawnId)
    {
        if (!GameObject::DeleteFromDB(spawnId))
            return Answer(handler, false, Trinity::StringFormat("no gameobject spawn {}", spawnId));
        return Answer(handler, true, Trinity::StringFormat("deleted go {}", spawnId));
    }
};

void AddSC_forge_commandscript()
{
    new forge_commandscript();
}
