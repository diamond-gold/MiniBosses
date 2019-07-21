# MiniBosses
Adds very customisable Bosses
# Commands
Create and spawn boss: /minibosses create network-id/entityType Name

Spawn boss: /minibosses spawn Name

Remove boss: /minibosses delete Name

List bosses in the config: /minibosses list

#Supported Entities
Chicken,Cow,Pig,Sheep,Wolf,Villager,Mooshroom,Squid,Rabbit,Bat,IronGolem,SnowGolem,Ocelot,
Horses,Zombie,Creeper,Skeleton,Spider,pigman,slime,enderman,silverfish,cavespider,ghast,magmacube
,blaze,zombievillager,witch,stray,husk,witherskeleton,wither,enderdragon,shulker,endermite,human,
vindicator,phantom,armorstand,pufferfish,salmon,tropicalfish,cod,panda

# Config Explanation
```
BossName:
  network-id: 63
  x: 127.444900 #spawn position x
  "y": 4.000000 #spawn position y
  z: 160.134600 #spawn position z
  level: FLAT
  health: 20 #number of half hearts
  range: 10 #no. of blocks to stay in from spawn position, if exceeded will teleport back to spawn and heal to full health
  attackDamage: 1 #number of half hearts
  attackRate: 10 #in ticks
  speed: 1
  drops: 1;0;1;;100 2;0;1;;50 3;0;1;;25 #in the format: ID;Damage;Count;NBT;DropChance(1-100),space separate items
  respawnTime: 100 #in ticks
  skin: #applicable only to human
    Name: ""
    Data: ""#skin hex
    CapeData: ""#skin cape hex
    GeometryName: geometry.humanoid.customSlim
    GeometryData: #geometry hex
  heldItem: "276;36;1;\n\x03\0tag\t\x04\0ench\n\x01\0\0\0\x02\x02\0id\x05\0\x02\x03\0lvl\x01\0\0\0"#in the format: ID;Damage;Count;NBT
  scale: 1
  autoAttack: false#auto attack players when they are in range
```