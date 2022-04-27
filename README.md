# MiniBosses
Adds very customisable Bosses
# Commands
Create and spawn boss: /minibosses create network-id/entityType Name

Spawn boss: /minibosses spawn Name

Remove boss: /minibosses delete Name

List bosses in the config: /minibosses list

# Supported Entities
Theoretically any entity, not all entities tested

# Config Explanation
```
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
  scale: 1
  autoAttack: false #auto attack players when they are in range
  width: 1 #before scale is applied
  height: 1 #before scale is applied
  jumpStrength: 2 #number of blocks to jump
  gravity: 0.08 #amount to add to motion y every tick, set to 0 for no gravity
  spreadDrops: false #whether to spread out the drops
  xpDrop: 0
  commands: #commands to execute when killed, command will not execute if player is required but boss is killed by non player damage
   - CONSOLE say Hi {PLAYER} {BOSS} #execute as console if prefixed with CONSOLE, {PLAYER} as player name, {BOSS} as boss name
   - OP say {BOSS} #temp set player as OP and execute command on behalf of player
   - me Hi
  projectile: #Boss will always priortize firing projectile over attacking if within range specified below
    networkId: minecraft:arrow #any entity except player
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
  armor: #will reduce damage taken
  - 310;0;1;0a000000 #ID;Damage;Count;NBT hex
  - 299;0;1;0a000000 #ID;Damage;Count;NBT hex
  - 300;0;1;0a000000 #ID;Damage;Count;NBT hex
  - 301;0;1;0a000000 #ID;Damage;Count;NBT hex
  hurtModifiers: #any damage cause (integer, See EntityDamageEvent): multiplier
    1: 1 #entity attack: no change
    2: 0.2 #projectile: 80% damage reduction
    4: 0 #fall damage: negate all
  knockbackResistance: 0 #chance of negating knockback 0.00-1.00
  topRewards: #starts counting from 0
  #top 1
  - - item 1;0;1; #ID;Damage;Count;NBT hex
    - command CONSOLE say [{BOSS}] Top Damage by {PLAYER}
  #top 2
  - - item 1;0;1; #ID;Damage;Count;NBT hex
    - command CONSOLE say [{BOSS}] Top 2 Damage by {PLAYER}
  minions: #minions will inherit data of respective Boss and any data specified below will override inherited data
  #minions will disappear once out of range from spawn position or if target lost
  - name: BossName #you can use any boss, x,y,z,world will be automatically replaced with random position within spawnRange
    spawnInterval: 100 #in ticks
    spawnRange: 5 #random position within this range from current Boss position will be selected as spawn position
    health: 1 #optional override
    speed: 2 #optional override
    gravity: 0 #optional override
    drops: "" #recommended override, if not minion will drop Boss drops
    commands: [] #recommended override, if not minion will execute same commands in Boss
    topRewards: [] #recommended override, if not minion will give same rewards as Boss
    minions: [] #recommended override to prevent minion spawning minion disaster
```