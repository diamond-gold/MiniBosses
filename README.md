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
,blaze,zombievillager,witch,stray,husk,witherskeleton,wither,enderdragon,shulker,endermite,human

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
  drops: 1;0;1;;100 2;0;1;;50 3;0;1;;25 #in the format: ID;Damage:Count;NBT;DropChance(1-100),space separate items
  respawnTime: 100 #in ticks
  skin: "" #in hex, applicable only to human
  heldItem: "276;36;1;\n\x03\0tag\t\x04\0ench\n\x01\0\0\0\x02\x02\0id\x05\0\x02\x03\0lvl\x01\0\0\0"#in the format: ID;Damage;Count;NBT
  scale: 1
```

# Copyright
Copyright (C) 2016 wolfdale All Rights Reserved.

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see http://www.gnu.org/licenses/.
