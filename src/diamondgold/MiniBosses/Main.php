<?php

namespace diamondgold\MiniBosses;

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
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\World;
use ReflectionClass;

class Main extends PluginBase implements Listener
{
    public Config $data;
    /** @var string[][][] */
    private array $chunkLoadCache = [];

    protected function onEnable(): void
    {
        EntityFactory::getInstance()->register(Boss::class, function (World $world, CompoundTag $tag): Boss {
            return new Boss(EntityDataHelper::parseLocation($tag, $world), $tag);
        }, ["Boss"]);
        EntityFactory::getInstance()->register(BossProjectile::class, function (World $world, CompoundTag $tag): BossProjectile {
            return new BossProjectile(EntityDataHelper::parseLocation($tag, $world), null, $tag);
        }, ["BossProjectile"]);

        $this->getLogger()->debug("Checking config...");
        $this->data = new Config($this->getDataFolder() . "Bosses.yml", Config::YAML);
        /**
         * @var string $name
         * @var mixed[] $bossData
         */
        foreach ($this->data->getAll() as $name => $bossData) {
            if (!($bossData["enabled"] ?? true)) {
                continue;
            }
            $idTag = isset($bossData["network-id"]) ? "network-id" : "networkId";
            if (isset($bossData[$idTag])) {
                if (is_int($bossData[$idTag])) {
                    $networkId = LegacyEntityIdToStringIdMap::getInstance()->getLegacyToStringMap()[$bossData[$idTag]] ?? ($bossData[$idTag] === 63 ? EntityIds::PLAYER : null);
                    if ($networkId === null) {
                        $this->getLogger()->error("Failed to auto convert legacy int network id " . $bossData[$idTag] . ", please manually update to string id for boss $name");
                        $bossData['networkId'] = $bossData[$idTag];
                        unset($bossData['network-id']);
                        $bossData['enabled'] = false;
                        $this->getLogger()->warning("Disabled boss $name, set \"enabled\" to true to enable");
                    } else {
                        $this->getLogger()->info("Converted legacy int network id for boss $name, " . $bossData[$idTag] . " => $networkId");
                        $bossData['networkId'] = $networkId;
                        unset($bossData['network-id']);
                        $this->data->set($name, $bossData);
                    }
                } elseif (is_string($bossData[$idTag])) {
                    if (!str_starts_with($bossData["networkId"], "minecraft:") && !str_contains($bossData["networkId"], ":")) {
                        $this->getLogger()->info("Updated networkId of boss $name " . $bossData["networkId"] . " => minecraft:" . $bossData["networkId"]);
                        $bossData["networkId"] = "minecraft:" . $bossData["networkId"];
                        $this->data->set($name, $bossData);
                    }
                    $constants = (new ReflectionClass(EntityIds::class))->getConstants();
                    if (str_starts_with($bossData["networkId"], "minecraft:") && !in_array($bossData["networkId"], $constants, true)) {
                        $this->getLogger()->error("Unknown networkId " . $bossData["networkId"] . " for boss $name");
                        $bossData['enabled'] = false;
                        $this->data->set($name, $bossData);
                        $this->getLogger()->warning("Disabled boss $name, set \"enabled\" to true to enable");
                    }
                }
            }
            if (isset($bossData["level"])) {
                $bossData['world'] = $bossData['level'];
                unset($bossData['level']);
                $this->data->set($name, $bossData);
                $this->getLogger()->info("Renamed level to world in config for boss $name");
            }
            if (isset($bossData["minions"])) {
                foreach ($bossData["minions"] as $id => $minion) {
                    if (!$this->data->exists($minion["name"])) {
                        $this->getLogger()->error("Boss minion $id of boss $name has non existent boss name \"" . $minion["name"] . "\" set");
                        $bossData['enabled'] = false;
                        $this->data->set($name, $bossData);
                        $this->getLogger()->warning("Disabled boss $name, set \"enabled\" to true to enable");
                    }
                }
            }
            if (isset($bossData['projectile'])) {
                $bossData['projectiles'] = [$bossData['projectile']];
                unset($bossData['projectile']);
                $this->data->set($name, $bossData);
                $this->getLogger()->info("Renamed projectile to projectiles in config for boss $name");
            }
            if (isset($bossData['drops']) && is_string($bossData['drops'])) {
                $drops = [];
                foreach (explode(' ', $bossData['drops']) as $itemStr) {
                    if ($itemStr !== '') {
                        $drops[] = $itemStr;
                    }
                }
                $bossData['drops'] = $drops;
                $this->data->set($name, $bossData);
                $this->getLogger()->info("Changed drops to array for boss $name");
            }
            if (isset($bossData['minions']) && is_array($bossData['minions'])) {
                $changed = false;
                foreach ($bossData['minions'] as $id => $minion) {
                    if (isset($minion['drops']) && is_string($minion['drops'])) {
                        $drops = [];
                        foreach (explode(' ', $minion['drops']) as $itemStr) {
                            if ($itemStr !== '') {
                                $drops[] = $itemStr;
                            }
                        }
                        $bossData['minions'][$id]['drops'] = $drops;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->data->set($name, $bossData);
                    $this->getLogger()->info("Changed drops of minions to array for boss $name");
                }
            }
        }

        $this->getLogger()->debug("Testing all bosses...");
        $tested = 0;
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        if ($world === null) {
            throw new AssumptionFailedError("Default world is null");
        }
        $loc = Location::fromObject($world->getSpawnLocation(), $world);
        /**
         * @var string $name
         */
        foreach ($this->data->getAll() as $name => $data) {
            if ($data["enabled"] ?? true) {
                $tested++;
                $boss = (new Boss($loc, CompoundTag::create()->setString("CustomName", $name)));
                if (!$boss->isFlaggedForDespawn()) {
                    foreach ($boss->projectileOptions as $options) {
                        if (!empty($options["networkId"]) || !empty($options["particle"])) {
                            $projectile = (new BossProjectile($loc, $boss));
                            $projectile->setData($options);
                            if ($projectile->isFlaggedForDespawn()) {
                                $boss->flagForDespawn();
                            }
                            $projectile->flagForDespawn();
                        }
                    }
                    foreach ($boss->minionOptions as $id => $option) {
                        $minion = $boss->spawnMinion($id, $option);
                        if (!$minion) {
                            $boss->flagForDespawn();
                        }
                    }
                }
                if ($boss->isFlaggedForDespawn()) {
                    $data['enabled'] = false;
                    $this->data->set($name, $data);
                    $this->getLogger()->warning("Disabled boss $name, set \"enabled\" to true to enable");
                }
                $boss->flagForDespawn();
            }
        }
        if ($this->data->hasChanged()) {
            copy($this->data->getPath(), $this->data->getPath() . ".bak");
            $this->getLogger()->info("Old config saved to " . $this->data->getPath() . ".bak");
            $this->data->save();
        }
        $this->getLogger()->debug("Done! Tested $tested/" . count($this->data->getAll()) . " bosses");
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getLoadedChunks() as $hash => $chunk) {
                World::getXZ($hash, $x, $z);
                $this->ChunkLoadEvent(new ChunkLoadEvent($world, $x, $z, $chunk, false));
            }
        }
    }

    protected function onDisable(): void
    {
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getEntities() as $entity) {
                if ($entity instanceof Boss || $entity instanceof BossProjectile) {
                    $entity->flagForDespawn();
                }
            }
        }
    }

    public function ChunkLoadEvent(ChunkLoadEvent $event): void
    {
        if (empty($this->chunkLoadCache)) {
            /**
             * @var string $name
             */
            foreach ($this->data->getAll() as $name => $data) {
                if ($data["enabled"] ?? true) {
                    $this->chunkLoadCache[$data["world"]][(intval($data["x"]) >> 4) . " " . (intval($data["z"]) >> 4)][] = $name;
                }
            }
        }
        if (isset($this->chunkLoadCache[$event->getWorld()->getFolderName()][$event->getChunkX() . " " . $event->getChunkZ()])) {
            foreach ($this->chunkLoadCache[$event->getWorld()->getFolderName()][$event->getChunkX() . " " . $event->getChunkZ()] as $name) {
                $this->spawnBoss($name);
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool
    {
        $argsCount = count($args);
        switch (array_shift($args)) {
            case "create":
                if (!($sender instanceof Player)) {
                    $sender->sendMessage("Please run in-game");
                } elseif ($argsCount >= 3) {
                    $networkId = (string)array_shift($args);
                    $name = implode(' ', $args);
                    if ($this->data->get($name, null) === null) {
                        if (is_numeric($networkId)) {
                            $sender->sendMessage("Legacy int network id may not be supported in the future, please use string id instead");
                            $networkId = (int)$networkId;
                            $legacyToString = LegacyEntityIdToStringIdMap::getInstance()->getLegacyToStringMap();
                            if (!isset($legacyToString[$networkId])) {
                                $sender->sendMessage(TF::RED . "Unrecognised Network ID or Entity type $networkId");
                                return true;
                            }
                            $networkId = $legacyToString[$networkId];
                        } else {
                            if (!str_starts_with($networkId, "minecraft:") && !str_contains($networkId, ":")) {
                                $networkId = "minecraft:" . $networkId;
                            }
                            $constants = (new ReflectionClass(EntityIds::class))->getConstants();
                            if (str_starts_with($networkId, "minecraft:") && !in_array($networkId, $constants, true)) {
                                $sender->sendMessage(TF::RED . "Unrecognised Network ID or Entity type $networkId");
                                return true;
                            }
                        }
                        $heldItem = $sender->getInventory()->getItemInHand();
                        $offhandItem = $sender->getOffHandInventory()->getItem(0);
                        $skin = $sender->getSkin();
                        $pos = $sender->getPosition();
                        $data = array_merge(Boss::BOSS_OPTIONS_DEFAULT, [
                            "networkId" => $networkId,
                            "x" => $pos->x, "y" => $pos->y, "z" => $pos->z, "world" => $pos->getWorld()->getFolderName(),

                            "heldItem" => ((StringToItemParser::getInstance()->lookupAliases($heldItem)[0] ?? "air") . ";" . ($heldItem instanceof Durable ? $heldItem->getDamage() : 0) . ";" . $heldItem->getCount() . ";" . bin2hex((new LittleEndianNbtSerializer())->write(new TreeRoot($heldItem->getNamedTag())))),
                            "offhandItem" => ((StringToItemParser::getInstance()->lookupAliases($offhandItem)[0] ?? "air") . ";" . ($offhandItem instanceof Durable ? $offhandItem->getDamage() : 0) . ";" . $offhandItem->getCount() . ";" . bin2hex((new LittleEndianNbtSerializer())->write(new TreeRoot($offhandItem->getNamedTag())))),
                            "projectiles" => [],
                            "armor" => array_map(function (Item $i): string {
                                return (StringToItemParser::getInstance()->lookupAliases($i)[0] ?? "air") . ";" . ($i instanceof Durable ? $i->getDamage() : 0) . ";" . $i->getCount() . ";" . bin2hex((new LittleEndianNbtSerializer())->write(new TreeRoot($i->getNamedTag())));
                            }, $sender->getArmorInventory()->getContents(true)),
                            "minions" => []
                        ]);
                        if ($networkId === EntityIds::PLAYER) {
                            $data["skin"] = ["Name" => $skin->getSkinId(), "Data" => bin2hex($skin->getSkinData()), "CapeData" => bin2hex($skin->getCapeData()), "GeometryName" => $skin->getGeometryName(), "GeometryData" => $skin->getGeometryData()];
                        }
                        $this->data->set($name, $data);
                        $this->data->save();
                        $this->chunkLoadCache = [];
                        $sender->sendMessage(TF::GREEN . "Successfully created MiniBoss: $name");
                        $sender->sendMessage("Please set \"enabled\" to true after configuration");
                    } else {
                        $sender->sendMessage(TF::RED . "That MiniBoss already exists!");
                    }
                } else {
                    $sender->sendMessage(TF::RED . "Usage: /minibosses create networkId name");
                }
                break;
            case "spawn":
                if ($argsCount >= 2) {
                    $name = implode(' ', $args);
                    if ($this->data->get($name, null) !== null) {
                        $ret = $this->spawnBoss($name);
                        if ($ret instanceof Boss) {
                            $sender->sendMessage(TF::GREEN . "Successfully spawned $name");
                        } else {
                            $sender->sendMessage(TF::RED . "Error spawning $name : $ret");
                        }
                    } else {
                        $sender->sendMessage(TF::RED . "That MiniBoss doesn't exist!");
                    }
                } else {
                    $sender->sendMessage(TF::RED . "Usage: /minibosses spawn name");
                }
                break;
            case "despawn":
                if ($argsCount >= 2) {
                    $name = implode(' ', $args);
                    if ($this->data->get($name, null) !== null) {
                        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
                            foreach ($world->getEntities() as $entity) {
                                if ($entity instanceof Boss && $entity->getName() === $name) {
                                    $entity->flagForDespawn();
                                }
                            }
                        }
                        $sender->sendMessage(TF::GREEN . "Successfully despawned $name");
                        static $despawnWarned = [];
                        if (!isset($despawnWarned[$sender->getName()])) {
                            $sender->sendMessage(TF::YELLOW . "Warning: The Boss will still spawn when the chunk is loaded again");
                            $sender->sendMessage(TF::YELLOW . "This warning will not show again until the server is restarted");
                            $despawnWarned[$sender->getName()] = true;
                        }
                    } else {
                        $sender->sendMessage(TF::RED . "That MiniBoss doesn't exist!");
                    }
                } else {
                    $sender->sendMessage(TF::RED . "Usage: /minibosses despawn name");
                }
                break;
            case "toggleEnabled":
            case "toggleenabled":
                if ($argsCount >= 2) {
                    $name = implode(' ', $args);
                    $data = $this->data->get($name, null);
                    if ($data !== null) {
                        $enabled = $data['enabled'] ?? true;
                        static $toggleWarned = [];
                        if (!$enabled && !isset($toggleWarned[$sender->getName()])) {
                            $sender->sendMessage(TF::YELLOW . "Warning: The Boss may not have been checked/tested during startup and may contain errors");
                            $sender->sendMessage(TF::YELLOW . "The Boss may result in unknown behavior if so");
                            $sender->sendMessage(TF::YELLOW . "Run the command again if you wish to continue anyway");
                            $sender->sendMessage(TF::YELLOW . "This warning will not show again until the server is restarted");
                            $toggleWarned[$sender->getName()] = true;
                            break;
                        }
                        $enabled = !$enabled;
                        $data['enabled'] = $enabled;
                        $this->data->set($name, $data);
                        $this->data->save();
                        $this->chunkLoadCache = [];
                        $sender->sendMessage(TF::GREEN . "Successfully " . ($enabled ? "enabled" : "disabled") . " $name");
                    } else {
                        $sender->sendMessage(TF::RED . "That MiniBoss doesn't exist!");
                    }
                } else {
                    $sender->sendMessage(TF::RED . "Usage: /minibosses enable name");
                }
                break;
            case "delete":
                if ($argsCount >= 2) {
                    $name = implode(' ', $args);
                    if (($data = $this->data->get($name, null)) !== null) {
                        if ($this->getServer()->getWorldManager()->loadWorld($data["world"])) {
                            $l = $this->getServer()->getWorldManager()->getWorldByName($data["world"]);
                            if ($l instanceof World) {//not needed logic wise, but just in case
                                $pos = new Vector3($data["x"], $data["y"], $data["z"]);
                                $l->loadChunk($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4);
                                foreach ($l->getChunkEntities($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4) as $e) {
                                    if ($e instanceof Boss && $e->getName() === $name) {
                                        $e->flagForDespawn();
                                    }
                                }
                            }
                        }
                        $this->data->remove($name);
                        $this->data->save();
                        $this->chunkLoadCache = [];
                        $sender->sendMessage(TF::GREEN . "Successfully removed MiniBoss: $name");
                    } else {
                        $sender->sendMessage(TF::RED . "That MiniBoss doesn't exist!");
                    }
                } else {
                    $sender->sendMessage(TF::RED . "Usage: /minibosses delete name");
                }
                break;
            case "list":
                $sender->sendMessage(TF::GREEN . "----MiniBosses----");
                $list = "";
                foreach ($this->data->getAll() as $name => $data) {
                    $list .= ($list !== "" ? ", " : "") . (($data['enabled'] ?? true) ? TF::GREEN : TF::RED) . $name;
                }
                $sender->sendMessage($list);
                break;
            default:
                return false;
        }
        return true;
    }

    public function spawnBoss(string $name): Boss|string
    {
        $data = $this->data->get($name);
        if (!$data) {
            return "No data, Boss does not exist";
        }
        if (!($data["enabled"] ?? true)) {
            return "Boss disabled";
        }
        if (!$this->getServer()->getWorldManager()->loadWorld($data["world"])) {
            return "Failed to load world " . $data["world"];
        }
        $pos = new Location($data["x"], $data["y"], $data["z"], $this->getServer()->getWorldManager()->getWorldByName($data["world"]), 0, 0);
        if ($pos->getWorld()->loadChunk(intval($pos->x) >> 4, intval($pos->z) >> 4) === null) {
            return "Failed to load chunk at " . $pos . " (is it generated yet?)";
        }
        $ent = new Boss($pos, CompoundTag::create()->setString("CustomName", $name));
        $ent->spawnToAll();
        return $ent;
    }

    public function respawn(string $name, int $time): void
    {
        if ($this->data->get($name)) {
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($name): void {
                $result = $this->spawnBoss($name);
                if (!$result instanceof Boss) {
                    $this->getLogger()->error("Boss $name failed to respawn: " . $result);
                }
            }), $time);
        }
    }

    /**
     * @param Boss $boss
     * @param Player|null $p
     * @param string[] $commands
     * @return void
     */
    public function executeCommands(Boss $boss, ?Player $p, array $commands = []): void
    {
        $name = $boss->getName();
        if (empty($commands)) {
            $data = $this->data->get($name);
            if ($boss->isMinion) {
                $data = $data["minions"][$boss->minionId] ?? [];
            }
            if (isset($data["commands"]) && is_array($data["commands"])) {
                $commands = $data["commands"];
            }
        }
        foreach ($commands as $command) {
            if (str_contains($command, "{PLAYER}") && $p === null) {
                continue;
            }
            $bossPos = $boss->getPosition();
            $command = str_replace(
                ["{PLAYER}", "{BOSS}", "{X}", "{Y}", "{Z}", "{WORLD}"],
                [$p?->getName(), $name, $bossPos->getX(), $bossPos->getY(), $bossPos->getZ(), $bossPos->getWorld()->getDisplayName()],
                $command
            );
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
            } else {
                continue;
            }
            $this->getServer()->dispatchCommand($sender, $command);
            if (isset($runAsOp) && $runAsOp && isset($op) && !$op) {
                $p?->setBasePermission(DefaultPermissions::ROOT_OPERATOR, false);
            }
        }
    }
}
