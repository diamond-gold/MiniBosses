# MiniBosses

Adds very customizable Bosses

[![Feature Requests](https://img.shields.io/github/issues-raw/diamond-gold/MiniBosses/Feature%20Request?label=Feature%20Requests&logo=github&style=for-the-badge)](https://github.com/diamond-gold/MiniBosses/issues)
[![Bug Reports](https://img.shields.io/github/issues-raw/diamond-gold/MiniBosses/bug?label=Bug%20Reports&logo=github&style=for-the-badge)](https://github.com/diamond-gold/MiniBosses/issues)
[![Total Downloads](https://img.shields.io/github/downloads/diamond-gold/MiniBosses/total?style=for-the-badge&logo=github)](https://github.com/diamond-gold/MiniBosses/releases)

[![Release](https://img.shields.io/github/release/diamond-gold/MiniBosses?style=for-the-badge&logo=github)](https://github.com/diamond-gold/MiniBosses/releases/latest)
[![Latest Release Downloads](https://img.shields.io/github/downloads/diamond-gold/MiniBosses/latest/total?style=for-the-badge&logo=github)](https://github.com/diamond-gold/MiniBosses/releases/latest)

### Poggit

[Latest dev build](https://poggit.pmmp.io/ci/diamond-gold/MiniBosses/~)

[![](https://poggit.pmmp.io/ci.shield/diamond-gold/MiniBosses/MiniBosses?style=for-the-badge)](https://poggit.pmmp.io/ci/diamond-gold/MiniBosses/~)

[Latest release](https://poggit.pmmp.io/get/MiniBosses/)

[![](https://poggit.pmmp.io/shield.api/MiniBosses?style=for-the-badge)](https://poggit.pmmp.io/p/MiniBosses)
[![](https://poggit.pmmp.io/shield.downloads/MiniBosses?style=for-the-badge)](https://poggit.pmmp.io/p/MiniBosses)
[![](https://poggit.pmmp.io/shield.downloads.total/MiniBosses?style=for-the-badge)](https://poggit.pmmp.io/p/MiniBosses)
[![](https://poggit.pmmp.io/shield.state/MiniBosses?style=for-the-badge)](https://poggit.pmmp.io/p/MiniBosses)

# Commands

Permission: `minibosses.command`

Create boss: `/minibosses create <entityType> <BossName>`

Spawn boss: `/minibosses spawn <BossName>`

Remove boss: `/minibosses delete <BossName>`

List bosses in the config: `/minibosses list`

# Supported Entities
Theoretically any entity, not all entities tested

# Features
Configurable
* Attributes
  * Entity type
  * Health
  * Attack damage
  * Attack rate
  * Movement speed
  * Gravity
  * Scale
  * Hurt modifiers (Reduced arrow damage? No fall damage? No problem!)
  * Knockback resistance
* Equipment
  * Held item
  * Offhand
  * Armor
* Health display
* Item/XP drops (option to spread drops)
* Respawn time
* Skin (4D skin supported)
* Commands on death
* Projectile
  * Entity type
  * Speed
  * Firing Range
  * Fire rate
  * Damage
  * Explosion radius
  * Health
  * Can be attacked/deflected
  * Despawn time
  * Gravity
  * Explosive flying pigs anyone? :P
* Top damage rewards (items/commands)
* Minions (Configured in the same manner as a Boss, can do anything a Boss can do)

# Configuring items with `Custom Name` / `Lore` / `Enchantments`
### In-game (only works for held item / armor)
1. Give yourself the desired item (with NBT if applicable) `/give`
2. (Optional) Enchant the item `/enchant`
3. (Optional) Modify the item with any other plugin
4. Equip/Hold the item
5. Create a Boss
6. (Optional) Copy the value in config to anywhere that accept item (For example `drops`)

Example unbreakable Diamond Sword named MyItem with Sharpness V Enchantment:
`diamond_sword;0;1;0a0000010b00556e627265616b61626c65010a0700646973706c61790804004e616d6506004d794974656d0904004c6f7265080200000005004c6f72653105004c6f72653200090400656e63680a01000000020200696409000203006c766c05000000`

### In Config (available in v3.2+)
1. Use any *Java Edition* \****1.12***\* `/give` command generator available online [[Example](https://www.gamergeeks.net/apps/minecraft/give-command-generator)] (Select Java 1.12) 
2. Modify the result obtained
   1. Note that *Java Edition* enchantment IDs are different from *Bedrock Edition*, convert IDs accordingly [[Bedrock Enchantment IDs](https://github.com/pmmp/PocketMine-MP/blob/stable/src/data/bedrock/EnchantmentIds.php)]
   2. Depending on the used command generator, you may need to correct the tag values
      1. Add `b` behind `ByteTag` values (`Unbreakable:1b` etc)
      2. Add `s` behind `ShortTag` values (`ench:[{id:9s,lvl:5s}` etc)
      3. Add `l` behind `LongTag` values
      4. Add `f` behind `FloatTag` values
      4. Add `d` behind `DoubleTag` values
3. Paste the result value anywhere that accept item (For example `drops`)

Example unbreakable Diamond Sword named MyItem with Sharpness V Enchantment:
`diamond_sword;0;1;{Unbreakable:1b,display:{Name:MyItem,Lore:[Lore1,Lore2]},ench:[{id:9s,lvl:5s}]}`
# Config Explanation
```yaml
BossName:
  network-id: minecraft:player
  x: 127.444900 #spawn position x
  "y": 4.000000 #spawn position y
  z: 160.134600 #spawn position z
  world: FLAT
  health: 20 #no. of half hearts
  range: 10 #no. of blocks to stay in from spawn position, if exceeded will teleport back to spawn and heal to full health
  attackDamage: 1 #no. of half hearts
  attackRate: 10 #in ticks
  speed: 1
  drops: 1;0;1;;100 2;0;1;;50 3;0;1;;25 #in the format: ID;Damage;Count;NBT hex;DropChance(1-100),space separate items
  respawnTime: 100 #in ticks
  skin: #applicable only to minecraft:player
    Name: ""
    Data: "" #skin hex
    CapeData: "" #skin cape hex
    GeometryName: ""
    GeometryData: "" #geometry hex/json
  heldItem: "276;0;1;" #for display only, in the format: ID;Damage;Count;NBT hex
  offhandItem: "276;0;1;" #for display only, in the format: ID;Damage;Count;NBT hex
  scale: 1
  autoAttack: false #auto attack players when they are in range
  width: 1 #before scale is applied
  height: 1 #before scale is applied
  jumpStrength: 2 #number of blocks to jump
  gravity: 0.08 #amount to subtract from motion y every tick, set to 0 for no gravity
  spreadDrops: false #whether to spread out the drops
  xpDrop: 0
  commands: #commands to execute when killed, command will not execute if player is required but boss is killed by non player damage
   - CONSOLE say Hi {PLAYER} {BOSS} #execute as console if prefixed with CONSOLE, {PLAYER} as player name, {BOSS} as boss name
   - OP say {BOSS} #temp set player as OP and execute command on behalf of player
   - me Hi
  projectile: #Boss will always prioritize firing projectile over attacking if within range specified below
    # [] for no projectile fired
    networkId: minecraft:arrow #any entity except player, note arrow/item cannot be attacked due to client-side limitation
    fireRangeMin: 5 #fire projectile if target within range Min Max
    fireRangeMax: 10
    speed: 1
    attackRate: 10 #in ticks
    attackDamage: 2 #base damage, no. of half hearts, scaled by speed just like any other projectile
    explodeRadius: 0 #explodes whenever it hits entity/block if more than 0
    explodeDestroyBlocks: false #USE WITH CAUTION
    health: 1
    canBeAttacked: false #whether the projectile can be hurt and deflected
    despawnAfter: 0 #in ticks, 0 = never despawn
    gravity: 0.04
    canBeDeflected: true #player can change direction of projectile by attacking it, requires canBeAttacked to be true
    followNearest: false #follow the nearest player
  armor: #will reduce damage taken
  - 310;0;1;0a000000 #ID;Damage;Count;NBT hex
  - 299;0;1;0a000000 #ID;Damage;Count;NBT hex
  - 300;0;1;0a000000 #ID;Damage;Count;NBT hex
  - 301;0;1;0a000000 #ID;Damage;Count;NBT hex
  hurtModifiers: #any damage cause (integer, See https://github.com/pmmp/PocketMine-MP/blob/stable/src/event/entity/EntityDamageEvent.php): multiplier
    1: 1 #entity attack: no change
    2: 0.2 #projectile: 80% damage reduction
    4: 0 #fall damage: negate all
  knockbackResistance: 0 #chance of negating knockback 0.00-1.00
  topRewards: #starts counting from 0, [] for no top rewards
  #top 1
  - - item 1;0;1; #ID;Damage;Count;NBT hex
    - command CONSOLE say [{BOSS}] Top Damage by {PLAYER}
  #top 2
  - - item 1;0;1; #ID;Damage;Count;NBT hex
    - command CONSOLE say [{BOSS}] Top 2 Damage by {PLAYER}
  minions: #minions will inherit data of respective Boss and any data specified below will override inherited data
  #minions will disappear once out of range from spawn position or if target is lost
  - name: BossName #you can use any boss, x,y,z,world will be automatically replaced with a random position within spawnRange
    spawnInterval: 100 #in ticks
    spawnRange: 5 #random position within this range from current Boss position will be selected as spawn position
    health: 1 #optional override
    speed: 2 #optional override
    gravity: 0 #optional override
    drops: "" #recommended override, if not minion will drop Boss drops
    commands: [] #recommended override, if not minion will execute same commands in Boss
    topRewards: [] #recommended override, if not minion will give same rewards as Boss
    minions: [] #recommended override to prevent minion spawning minion disaster
  displayHealth: "" # {HEALTH} => health value , {MAX_HEALTH} => maxHealth , {BAR} => health bar, example: "{HEALTH}/{MAX_HEALTH} {BAR}"
```