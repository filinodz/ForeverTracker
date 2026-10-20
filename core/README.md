# ForeverTracker core module

`forge_commands.cpp` adds the `.forge` commands to a TrinityCore worldserver (master / WoW Forever branches), so the
ForeverTracker Forge and the ForeverForge addon can spawn creatures and objects at world coordinates, with the
height computed by the server from its maps and vmaps.

Install: copy the file to `src/server/scripts/Custom/`, then in `src/server/scripts/Custom/custom_script_loader.cpp`:

```cpp
void AddSC_forge_commandscript();

void AddCustomScripts()
{
    // ... existing calls
    AddSC_forge_commandscript();
}
```

Rebuild the worldserver (CMake configures again and picks up the new file). No database change is needed.
`.forge version` answers `FORGE OK version 1`. The commands and their answers are listed in
[docs/SPAWNS.md](../docs/SPAWNS.md#commands-of-the-core-module).
