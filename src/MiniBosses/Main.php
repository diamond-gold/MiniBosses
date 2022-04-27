<?php

namespace MiniBosses;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\data\bedrock\LegacyEntityIdToStringIdMap;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\event\Listener;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\World;
use ReflectionClass;

class Main extends PluginBase implements Listener
{

    public Config $data;
    private array $chunkLoadCache = [];

    public function onEnable(): void
    {
        EntityFactory::getInstance()->register(Boss::class, function (World $world, CompoundTag $tag): Boss {
            return new Boss(EntityDataHelper::parseLocation($tag, $world), $tag);
        }, ["Boss"]);
        EntityFactory::getInstance()->register(BossProjectile::class, function (World $world, CompoundTag $tag): BossProjectile {
            return new BossProjectile(EntityDataHelper::parseLocation($tag, $world), null, $tag);
        }, ["BossProjectile"]);

        $this->getLogger()->info("Checking config...");
        $this->data = new Config($this->getDataFolder() . "Bosses.yml", Config::YAML);
        foreach ($this->data->getAll() as $name => $bossData) {
            if(!($bossData["enabled"] ?? true)) continue;
            $idTag = isset($bossData["network-id"]) ? "network-id" : "networkId";
            if(isset($bossData[$idTag])) {
                if (is_int($bossData[$idTag])) {
                    $networkId = LegacyEntityIdToStringIdMap::getInstance()->getLegacyToStringMap()[$bossData[$idTag]] ?? ($bossData[$idTag] === 63 ? EntityIds::PLAYER : null);
                    if ($networkId === null) {
                        $this->getLogger()->error("Failed to auto convert legacy int network id " . $bossData[$idTag] . ", please manually update to string id for boss $name");
                        $this->data->setNested("$name.networkId", $bossData[$idTag]);
                        $this->data->removeNested("$name.network-id");
                        $this->data->setNested("$name.enabled", false);
                        $this->getLogger()->warning("Disabled boss $name, set \"enabled\" to true to enable");
                    } else {
                        $this->getLogger()->info("Converted legacy int network id for boss $name, " . $bossData[$idTag] . " => $networkId");
                        $this->data->setNested("$name.networkId", $networkId);
                        $this->data->removeNested("$name.network-id");
                    }
                } else if (is_string($bossData[$idTag])) {
                    if (!str_starts_with($bossData["networkId"], "minecraft:")) {
                        $bossData["networkId"] = "minecraft:" . $bossData["networkId"];
                        $this->getLogger()->info("Updated networkId of boss $name " . $bossData["networkId"] . " => minecraft:" . $bossData["networkId"]);
                        $this->data->setNested("$name.networkId", $bossData["networkId"]);
                    }
                    $constants = (new ReflectionClass(EntityIds::class))->getConstants();
                    if (!in_array($bossData["networkId"], $constants, true)) {
                        $this->getLogger()->error("Unknown networkId " . $bossData["networkId"] . " for boss $name");
                        $this->data->setNested("$name.enabled", false);
                        $this->getLogger()->warning("Disabled boss $name, set \"enabled\" to true to enable");
                    }
                }
            }
            if (isset($bossData["level"])) {
                $this->data->setNested("$name.world", $bossData["level"]);
                $this->data->removeNested("$name.level");
                $this->getLogger()->info("Renamed level to world in config for boss $name");
            }
            if (isset($bossData["minions"])) {
                foreach ($bossData["minions"] as $id => $minion) {
                    if (!$this->data->exists($minion["name"])) {
                        $this->getLogger()->error("Boss minion $id of boss $name has non existent boss name \"" . $minion["name"] . "\" set");
                        $this->data->setNested("$name.enabled",false);
                        $this->getLogger()->warning("Disabled boss $name, set \"enabled\" to true to enable");
                    }
                }
            }
        }

        $this->getLogger()->info("Testing all bosses...");
        $tested = 0;
        $loc = Location::fromObject($this->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation(), $this->getServer()->getWorldManager()->getDefaultWorld());
        foreach($this->data->getAll() as $name => $data) {
            if($data["enabled"] ?? true) {
                $tested++;
                $boss = (new Boss($loc, CompoundTag::create()->setString("CustomName", $name)));
                if(isset($boss->projectileOptions["networkId"])) {
                    $projectile = (new BossProjectile($loc, $boss, CompoundTag::create()->setString("networkId", $boss->projectileOptions["networkId"])));
                    if($projectile->isFlaggedForDespawn()){
                        $boss->flagForDespawn();
                    }
                    $projectile->flagForDespawn();
                }
                foreach ($boss->minionOptions as $id => $option){
                    $minion = $boss->spawnMinion($id,$option);
                    if(!$minion)
                        $boss->flagForDespawn();
                }
                if($boss->isFlaggedForDespawn()){
                    $this->data->setNested("$name.enabled",false);
                    $this->getLogger()->warning("Disabled boss $name, set \"enabled\" to true to enable");
                }
                $boss->flagForDespawn();
            }
        }
        if ($this->data->hasChanged()) {
            copy($this->data->getPath(),$this->data->getPath().".bak");
            $this->data->save();
        }
        $this->getLogger()->info("Done! Tested $tested/".count($this->data->getAll())." bosses");
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world){
            foreach ($world->getLoadedChunks() as $hash => $chunk){
                World::getXZ($hash,$x,$z);
                $this->ChunkLoadEvent(new ChunkLoadEvent($world,$x,$z,$chunk,false));
            }
        }
    }

    protected function onDisable(): void
    {
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world)
            foreach ($world->getEntities() as $entity)
                if($entity instanceof Boss)
                    $entity->flagForDespawn();
    }

    public function ChunkLoadEvent(ChunkLoadEvent $event)
    {
        if (empty($this->chunkLoadCache)) {
            foreach ($this->data->getAll() as $name => $data) {
                if($data["enabled"] ?? true)
                    $this->chunkLoadCache[$data["world"]][($data["x"] >> 4) . " " . ($data["z"] >> 4)] = $name;
            }
        }
        if (isset($this->chunkLoadCache[$event->getWorld()->getFolderName()][$event->getChunkX() . " " . $event->getChunkZ()])) {
            $this->spawnBoss($this->chunkLoadCache[$event->getWorld()->getFolderName()][$event->getChunkX() . " " . $event->getChunkZ()]);
        }
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool
    {
        $argsCount = count($args);
        switch (array_shift($args)) {
            case "create":
                if (!($sender instanceof Player)) $sender->sendMessage("Please run in-game");
                elseif ($argsCount >= 3) {
                    $networkId = array_shift($args);
                    $name = implode(' ', $args);
                    if ($this->data->get($name, null) === null) {
                        if (is_numeric($networkId)) {
                            $sender->sendMessage("Legacy int network id may not be supported in the future, please use string id instead");
                        } else {
                            if (!str_starts_with($networkId, "minecraft:")) $networkId = "minecraft:" . $networkId;
                            $constants = (new ReflectionClass(EntityIds::class))->getConstants();
                            if (in_array($networkId, $constants, true)) {
                                // Do absolutely nothing.
                            } else {
                                $sender->sendMessage(TF::RED . "Unrecognised Network ID or Entity type $networkId");
                                return true;
                            }
                        }
                        $heldItem = $sender->getInventory()->getItemInHand();
                        $skin = $sender->getSkin();
                        $pos = $sender->getPosition();
                        $data = array_merge(Boss::BOSS_OPTIONS_DEFAULT, [
                            "networkId" => $networkId,
                            "x" => $pos->x, "y" => $pos->y, "z" => $pos->z, "world" => $pos->getWorld()->getFolderName(),

                            "heldItem" => ($heldItem->getId() . ";" . ($heldItem instanceof Durable ? $heldItem->getDamage() : 0) . ";" . $heldItem->getCount() . ";" . bin2hex((new LittleEndianNbtSerializer())->write(new TreeRoot($heldItem->getNamedTag())))),
                            "projectile" => Boss::PROJECTILE_OPTIONS_DEFAULT,
                            "armor" => array_map(function (Item $i): string {
                                return $i->getId() . ";" . ($i instanceof Durable ? $i->getDamage() : 0) . ";" . $i->getCount() . ";" . bin2hex((new LittleEndianNbtSerializer())->write(new TreeRoot($i->getNamedTag())));
                            }, $sender->getArmorInventory()->getContents(true)),
                            "minions" => [["name" => $name, "spawnInterval" => 100, "spawnRange" => 5, "health" => 1, "gravity" => 0, "drops" => "", "minions" => [], "commands" => []]]
                        ]);
                        if ($networkId === EntityIds::PLAYER)
                            $data["skin"] = ["Name" => $skin->getSkinId(), "Data" => bin2hex($skin->getSkinData()), "CapeData" => bin2hex($skin->getCapeData()), "GeometryName" => $skin->getGeometryName(), "GeometryData" => json_encode($skin->getGeometryData())];
                        $this->data->set($name, $data);
                        $this->data->save();
                        $this->chunkLoadCache = [];
                        $sender->sendMessage(TF::GREEN . "Successfully created MiniBoss: $name");
                        $sender->sendMessage("Please set \"enabled\" to true after configuration");
                    } else
                        $sender->sendMessage(TF::RED . "That MiniBoss already exists!");
                } else
                    $sender->sendMessage(TF::RED . "Usage: /minibosses create networkId name");
                break;
            case "spawn":
                if ($argsCount >= 2) {
                    $name = implode(' ', $args);
                    if ($this->data->get($name, null) !== null) {
                        $ret = $this->spawnBoss($name);
                        if ($ret instanceof Boss)
                            $sender->sendMessage("Successfully spawned $name");
                        else
                            $sender->sendMessage(TF::RED . "Error spawning $name : $ret");
                    } else
                        $sender->sendMessage(TF::RED . "That MiniBoss doesn't exist!");
                } else
                    $sender->sendMessage(TF::RED . "Usage: /minibosses spawn name");
                break;
            case "delete":
                if ($argsCount >= 2) {
                    $name = implode(' ',$args);
                    if (($data = $this->data->get($name, null)) !== null) {
                        if ($this->getServer()->getWorldManager()->loadWorld($data["world"])) {
                            $l = $this->getServer()->getWorldManager()->getWorldByName($data["world"]);
                            $l->loadChunk($data["x"] >> 4, $data["z"] >> 4);
                            foreach ($l->getChunkEntities($data["x"] >> 4, $data["z"] >> 4) as $e) {
                                if ($e instanceof Boss && $e->getName() === $name)
                                    $e->flagForDespawn();
                            }
                        }
                        $this->data->remove($name);
                        $this->data->save();
                        $this->chunkLoadCache = [];
                        $sender->sendMessage(TF::GREEN . "Successfully removed MiniBoss: $name");
                    } else
                        $sender->sendMessage(TF::RED . "That MiniBoss doesn't exist!");
                } else
                    $sender->sendMessage(TF::RED . "Usage: /minibosses delete name");
                break;
            case "list":
                $sender->sendMessage(TF::GREEN . "----MiniBosses----");
                $sender->sendMessage(implode(', ', array_keys($this->data->getAll())));
                break;
            default:
                return false;
        }
        return true;
    }

    public function spawnBoss(string $name): Boss|string
    {
        $data = $this->data->get($name);
        if (!$data)
            return "No data, Boss does not exist";
        if(!($data["enabled"] ?? true))
            return "Boss disabled";
        if (!$this->getServer()->getWorldManager()->loadWorld($data["world"]))
            return "Failed to load world " . $data["world"];
        $pos = new Location($data["x"], $data["y"], $data["z"], $this->getServer()->getWorldManager()->getWorldByName($data["world"]), 0, 0);
        if($pos->getWorld()->loadChunk($pos->x >> 4, $pos->z >> 4) === null)
            return "Failed to load chunk at ".$pos;
        $ent = new Boss($pos, CompoundTag::create()->setString("CustomName", $name));
        $ent->spawnToAll();
        return $ent;
    }

    public function respawn(string $name, int $time)
    {
        if ($this->data->get($name)) $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($name): void {
            $result = $this->spawnBoss($name);
            if (!$result instanceof Boss)
                $this->getLogger()->error("Boss $name failed to respawn: " . $result);
        }), $time);
    }

    function executeCommands(Boss $boss, ?Player $p, array $commands = [])
    {
        $name = $boss->getNameTag();
        if(empty($commands)) {
            $data = $this->data->get($name);
            if ($boss->isMinion) {
                $data = $data["minions"][$boss->minionId] ?? [];
            }
            if(isset($data["commands"]) && is_array($data["commands"])){
                $commands = $data["commands"];
            }
        }
        foreach ($commands as $command) {
            if (str_contains($command, "{PLAYER}") && $p === null) continue;
            $command = str_replace(["{PLAYER}", "{BOSS}"], [$p->getName(), $name], $command);
            if (str_starts_with($command, "CONSOLE ")) {
                $command = substr($command, strlen("CONSOLE "));
                $sender = new ConsoleCommandSender($this->getServer(), $this->getServer()->getLanguage());
            } elseif ($p instanceof Player) {
                $sender = $p;
                $op = $p->hasPermission(DefaultPermissions::ROOT_OPERATOR);
                $runAsOp = str_starts_with($command, "OP ");
                if ($runAsOp) {
                    $command = substr($command, strlen("OP "));
                    $p->setBasePermission(DefaultPermissions::ROOT_OPERATOR, true);
                }
            } else continue;
            $this->getServer()->dispatchCommand($sender, $command);
            if (isset($runAsOp) && $runAsOp && isset($op) && !$op)
                $p->setBasePermission(DefaultPermissions::ROOT_OPERATOR, false);
        }
    }
}