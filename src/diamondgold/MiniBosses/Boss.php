<?php

namespace diamondgold\MiniBosses;

use diamondgold\MiniBosses\data\DropsEntry;
use Exception;
use LogLevel;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\JsonNbtParser;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\SkinAdapterSingleton;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use stdClass;
use TypeError;

class Boss extends Living
{
    protected Main $plugin;
    public Position $spawnPos;
    public float $attackDamage;
    public float $speed;
    public float $range;
    public string $networkId;
    public int $attackRate;
    public int $attackDelay = 0;
    public int $respawnTime;
    public int $knockbackTicks = 0;
    public int $attackRange;
    /** @var DropsEntry[] */
    public array $drops = array();
    public ?Skin $skin = null;
    public Item $heldItem;
    public Item $offhandItem;
    public bool $autoAttack;
    public float $width;
    public float $height;
    public bool $spreadDrops;
    public int $xpDropAmount;
    /** @var float[][]|bool[][]|string[][] */
    public array $projectileOptions = [];
    /** @var float[] */
    public array $hurtModifiers = [];
    /** @var float[][]|bool[][]|string[][] */
    public array $minionOptions = [];
    /** @var int[] */
    public array $minionSpawnDelay = [];
    public bool $isMinion = false;
    public int $minionId = -1;
    /** @var string[][]|Item[][] */
    public array $topRewards = [];
    /** @var float[] */
    public array $topDamage = [];
    public string $displayHealth;
    public bool $movesByJumping;
    public int $despawnAfter;
    /** @var int[] */
    public array $projectileDelay = [];

    const PROJECTILE_OPTIONS_TYPE = [
        "networkId" => "string",
        "fireRangeMin" => "double",
        "fireRangeMax" => "double",
        "speed" => "double",
        "attackRate" => "integer",
        "attackDamage" => "double",
        "explodeRadius" => "double",
        "explodeDestroyBlocks" => "boolean",
        "health" => "double",
        "canBeAttacked" => "boolean",
        "despawnAfter" => "integer",
        "gravity" => "double",
        "canBeDeflected" => "boolean",
        "followNearest" => "boolean",
        "particle" => "string",
    ];

    const MINIONS_OPTIONS_TYPE = [
        "name" => "string",
        "spawnInterval" => "integer",
        "spawnRange" => "integer",
        "despawnAfter" => "integer",
    ];

    const PROJECTILE_OPTIONS_DEFAULT = [
        "networkId" => "",
        "fireRangeMin" => 0,
        "fireRangeMax" => 100,
        "speed" => 1,
        "attackRate" => 10,
        "attackDamage" => 1,
        "explodeRadius" => 0,
        "explodeDestroyBlocks" => false,
        "health" => 1,
        "canBeAttacked" => false,
        "despawnAfter" => 0,
        "gravity" => 0.04,
        "canBeDeflected" => true,
        "followNearest" => false,
        "particle" => "",
    ];

    const BOSS_OPTIONS_DEFAULT = [
        "enabled" => false,
        "health" => 20,
        "displayHealth" => "",
        "range" => 10,
        "attackDamage" => 1,
        "attackRate" => 10,
        "attackRange" => 1.5,
        "speed" => 1,
        "drops" => "",
        "respawnTime" => 100,
        "heldItem" => "",
        "offhandItem" => "",
        "scale" => 1,
        "autoAttack" => false,
        "width" => 1,
        "height" => 1,
        "jumpStrength" => 2,
        "gravity" => 0.08,
        "spreadDrops" => false,
        "xpDrop" => 0,
        "commands" => [],
        "projectiles" => [],
        "armor" => [],
        "hurtModifiers" => [],
        "knockbackResistance" => 0,
        "minions" => [],
        "topRewards" => [],
        "movesByJumping" => false,
    ];

    const MINIONS_OPTIONS_DEFAULT = [
        "despawnAfter" => 0,
    ];

    public function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);
        $this->setCanSaveWithChunk(false);
        $plugin = $this->server->getPluginManager()->getPlugin("MiniBosses");
        if (!$plugin instanceof Main) {
            $this->flagForDespawn();
            return;
        }
        $this->plugin = $plugin;
        if ($this->plugin->isDisabled()) {
            $this->flagForDespawn();
            $this->log(LogLevel::ERROR, "Despawn due to plugin disabled");
            return;
        }
        $data = $this->plugin->data->get($this->getName());
        if (!$data) {
            $this->flagForDespawn();
            $this->log(LogLevel::ERROR, "Despawn due to no data");
            return;
        }
        try {
            $this->parseData($data);
        } catch (Exception $e) {
            $this->flagForDespawn();
            $this->log(LogLevel::ERROR, "Despawn due to invalid data for boss: " . $e->getMessage());
        }
    }

    /**
     * @param mixed[] $data
     * @param bool $validateMinions
     * @return void
     * @throws Exception
     */
    private function parseData(array $data, bool $validateMinions = true): void
    {
        $this->width = $this->validateType($data, "width", "double");
        $this->height = $this->validateType($data, "height", "double");
        $this->setSize(new EntitySizeInfo($this->height, $this->width));
        $this->setScale($this->scale = $this->validateType($data, "scale", "double"));
        $this->networkId = $this->validateType($data, "networkId", "string");
        $this->range = $this->validateType($data, "range", "integer");
        $this->spawnPos = new Position(
            $this->validateType($data, "x", "double"),
            $this->validateType($data, "y", "double"),
            $this->validateType($data, "z", "double"),
            $this->server->getWorldManager()->getWorldByName($this->validateType($data, "world", "string"))
        );
        $this->attackDamage = $this->validateType($data, "attackDamage", "double");
        $this->attackRate = $this->validateType($data, "attackRate", "double");
        $this->attackRange = $this->validateType($data, "attackRange", "double", $this->scale * $this->width / 2 + 1);
        $this->speed = $this->validateType($data, "speed", "double");
        $this->drops = [];
        $drops = $this->validateType($data, "drops", "string");
        if ($drops !== "") {
            foreach (explode(' ', $drops) as $itemStr) { //TODO: change this, this is preventing space character usage in NBT json
                $explode = explode(';', $itemStr);
                $this->drops[] = new DropsEntry($this->parseItem($itemStr), (int)($explode[4] ?? 100));
            }
        }
        $this->respawnTime = $this->validateType($data, "respawnTime", "integer");
        $this->heldItem = $this->parseItem($this->validateType($data, "heldItem", "string"));
        $this->offhandItem = $this->parseItem($this->validateType($data, "offhandItem", "string"));
        if ($this->networkId === EntityIds::PLAYER) {
            try {
                if (is_string($data["skin"])) {//old data
                    $this->skin = new Skin(Uuid::uuid4()->toString(), $data["skin"]);
                } else {
                    $this->validateType($data, "skin", "array");
                    $geometryData = json_decode($data["skin"]["GeometryData"]);
                    if ($geometryData === null) {
                        $geometryData = hex2bin($data["skin"]["GeometryData"]);
                    } elseif ($geometryData instanceof stdClass) {
                        $geometryData = json_encode($geometryData);
                    }
                    $this->skin = new Skin($data["skin"]["Name"], (string)hex2bin($data["skin"]["Data"]), (string)hex2bin($data["skin"]["CapeData"]), $data["skin"]["GeometryName"], $geometryData);
                }
            } catch (Exception $e) {
                $this->log(LogLevel::ERROR, "Invalid skin: " . $e->getMessage());
                throw $e;
            }
        }
        $this->autoAttack = $this->validateType($data, "autoAttack", "boolean");
        $this->setImmobile();
        $this->setNameTagAlwaysVisible();
        $this->setNameTagVisible();
        if (isset($data["health"])) {
            $this->validateType($data, "health", "double");
            $this->setMaxHealth($data["health"]);
            $this->setHealth($data["health"]);
        }
        $this->jumpVelocity = $this->validateType($data, "jumpStrength", "double");
        $this->gravity = $this->validateType($data, "gravity", "double");
        $this->gravityEnabled = $this->gravity != 0;
        $this->spreadDrops = $this->validateType($data, "spreadDrops", "boolean");
        $this->xpDropAmount = $this->validateType($data, "xpDrop", "integer");
        $this->projectileOptions = $this->validateType($data, "projectiles", "array");
        foreach ($this->projectileOptions as $id => $projectileOptions) {
            if (isset($projectileOptions['networkId']) || isset($projectileOptions['particle'])) {
                foreach (self::PROJECTILE_OPTIONS_TYPE as $option => $type) {
                    $projectileOptions[$option] = $this->validateType($projectileOptions, $option, $type, self::PROJECTILE_OPTIONS_DEFAULT[$option], "Projectile $id");
                }
                if (!empty($projectileOptions["networkId"])) {
                    if (!str_starts_with($projectileOptions["networkId"], "minecraft:") && !str_contains($projectileOptions["networkId"], ":")) {
                        $projectileOptions["networkId"] = "minecraft:" . $projectileOptions["networkId"];
                    }
                    $constants = (new ReflectionClass(EntityIds::class))->getConstants();
                    if (str_starts_with($projectileOptions["networkId"], "minecraft:") && !in_array($projectileOptions["networkId"], $constants, true)) {
                        throw new Exception("Projectile $id: Unknown projectile entity type " . $projectileOptions["networkId"]);
                    }
                    if ($projectileOptions["networkId"] === EntityIds::PLAYER) {
                        throw new Exception("Projectile $id: " . EntityIds::PLAYER . " is not a valid projectile entity type, please use other entity");
                    }
                } elseif (empty($projectileOptions["particle"])) {
                    $this->log(LogLevel::WARNING, "Projectile $id is completely invisible");
                }
                if (!empty($projectileOptions["particle"]) && !str_starts_with($projectileOptions["particle"], "minecraft:")) {
                    $projectileOptions["particle"] = "minecraft:" . $projectileOptions["particle"];
                }
                $this->projectileOptions[$id] = $projectileOptions;
            }
        }
        foreach ($this->validateType($data, "armor", "array") as $i => $piece) {
            if (!is_int($i) || !$this->getArmorInventory()->slotExists($i)) {
                $this->log(LogLevel::ERROR, "Invalid slot $i for armor, skipping");
                continue;
            }
            $item = $this->parseItem($piece);
            $this->getArmorInventory()->setItem($i, $item);
        }
        $hurtModifiers = $this->validateType($data, "hurtModifiers", "array");
        $damageCauses = array_filter((new ReflectionClass(EntityDamageEvent::class))->getConstants(), function ($value, $key): bool {
            return str_contains($key, "CAUSE_");
        }, ARRAY_FILTER_USE_BOTH);
        foreach ($hurtModifiers as $cause => $multiplier) {
            if (!in_array($cause, $damageCauses)) {
                unset($hurtModifiers[$cause]);
                $this->log(LogLevel::ERROR, "hurtModifiers: Unknown damage cause " . $cause . ", skipping ");
                continue;
            }
            if (!is_float($multiplier) && !is_int($multiplier)) {
                unset($hurtModifiers[$cause]);
                $this->log(LogLevel::ERROR, "hurtModifiers: Invalid multiplier for cause " . $cause . ", skipping");
            }
        }
        $this->hurtModifiers = $hurtModifiers;
        $this->knockbackResistanceAttr->setValue($this->validateType($data, "knockbackResistance", "double"));
        $this->minionOptions = $this->validateType($data, "minions", "array");
        $this->topRewards = $this->validateType($data, "topRewards", "array");
        foreach ($this->topRewards as $top => $rewards) {
            if (is_array($rewards)) {
                foreach ($rewards as $i => $rewardStr) {
                    $r = explode(' ', $rewardStr);
                    switch (strtolower($r[0])) {
                        case "item":
                            $this->topRewards[$top][$i] = $this->parseItem(substr($rewardStr, strlen($r[0]) + 1));
                            break;
                        case "command":
                            $this->topRewards[$top][$i] = substr($rewardStr, strlen($r[0]) + 1);
                            break;
                        default:
                            unset($this->topRewards[$top][$i]);
                            $this->log(LogLevel::ERROR, "topRewards: Unknown reward $rewardStr, skipping");
                            break;
                    }
                }
            }
        }
        $this->displayHealth = $this->validateType($data, "displayHealth", "string");
        $this->movesByJumping = $this->validateType($data, "movesByJumping", "boolean");
        if ($this->movesByJumping && $this->gravity <= 0) {
            $this->log(LogLevel::WARNING, "movesByJumping is enabled but gravity is negative or zero, this will not work");
        }
        $this->despawnAfter = $this->validateType($data, "despawnAfter", "integer", self::MINIONS_OPTIONS_DEFAULT["despawnAfter"]);
        if ($validateMinions) {
            foreach ($this->minionOptions as $id => $minionData) {
                if (!is_int($id)) {
                    throw new Exception("Minion $id error: Minion id must be an integer");
                }
                try {
                    foreach (self::MINIONS_OPTIONS_TYPE as $option => $type) {
                        $this->validateType($minionData, $option, $type, self::MINIONS_OPTIONS_DEFAULT[$option] ?? null, "Minion $id");
                    }
                    $testMinionData = array_merge($minionData, ["x" => 0, "y" => 0, "z" => 0, "world" => "", "networkId" => EntityIds::PIG]);//dummy data that should be supplied by boss
                    $this->parseData($testMinionData);
                    if ($minionData['spawnRange'] > $this->range) {
                        $this->log(LogLevel::WARNING, "Minion $id has spawnRange (" . $minionData['spawnRange'] . ") that is larger than its range ($this->range), it will immediately despawn if there are no players in it's range");
                    }
                } catch (Exception $e) {
                    throw new Exception("Minion $id error: " . $e->getMessage());
                }
            }
            $this->parseData($data, false);
        }
    }

    /**
     * Throws Exception if data is not expected type and no default value
     * @param mixed[] $data
     * @param string $index
     * @param string $type
     * @param float|bool|string|null $defaultOverride
     * @param string $context
     * @return mixed
     */
    private function validateType(array $data, string $index, string $type, float|bool|string $defaultOverride = null, string $context = ""): mixed
    {
        $default = $defaultOverride ?? self::BOSS_OPTIONS_DEFAULT[$index] ?? null;
        if (!isset($data[$index])) {
            if ($default === null) {
                throw new SavedDataLoadingException("$context Missing required data \"$index\" of type $type");
            }
            return $default;
        }
        $dataType = gettype($data[$index]);
        if ($dataType === $type || ($dataType === "integer" && $type === "double")) {
            return $data[$index];
        }
        if ($type === "double") {
            $type = "integer/float/double";
        }
        throw new SavedDataLoadingException("$context \"$index\" must be $type, $dataType given");
    }

    private function parseItem(string $itemStr): Item
    {
        if ($itemStr === "") {
            return VanillaItems::AIR();
        }
        $arr = explode(";", $itemStr);
        try {
            if (!isset($arr[0]) || $arr[0] === "") {
                throw new Exception("Empty ID");
            }
            if (!is_numeric($arr[0])) {
                $item = StringToItemParser::getInstance()->parse($arr[0]);
                if ($item === null) {
                    throw new Exception("Unknown ID '$arr[0]'");
                }
                if ($item instanceof Durable && isset($arr[1])) {
                    $item->setDamage((int)$arr[1]);
                }
            } else {
                $item = ItemFactory::getInstance()->get((int)$arr[0], empty($arr[1]) ? 0 : (int)$arr[1]);
            }

            if (!empty($arr[2])) {
                $item->setCount((int)$arr[2]);
            }

            $nbt = null;
            if (!empty($arr[3])) {
                if (str_starts_with($arr[3], '{')) {
                    $nbt = JsonNbtParser::parseJson(str_replace('_', ' ', $arr[3]));
                } else {
                    $nbt = (new LittleEndianNbtSerializer())->read((string)hex2bin($arr[3]))->mustGetCompoundTag();
                }
            }
            if ($nbt !== null) {
                $item->setNamedTag($nbt);
            }
            return $item;
        } catch (Exception|TypeError $e) {
            $this->log(LogLevel::ERROR, "Failed to parse item $itemStr: " . $e->getMessage());
        }
        return VanillaItems::AIR();
    }

    protected function log(string $level, string $msg): void
    {
        $this->plugin->getLogger()->log($level, "[" . ($this->isMinion ? "Minion$this->minionId " . $this->getName() : $this->getName()) . "] " . $msg);
    }

    public function getName(): string
    {
        return $this->getNameTag();
    }

    public function setNameTag(string $name): void
    {
        if ($this->getNameTag() === "") {
            parent::setNameTag($name);
        } else {
            $this->plugin->getLogger()->logException(new Exception("Boss name tag should not be modified"));
        }
    }

    public function sendSpawnPacket(Player $player): void
    {
        if ($this->networkId === EntityIds::PLAYER) {
            if ($this->skin === null) {
                throw new AssumptionFailedError("Boss ".$this->getName()." has no skin");
            }
            $uuid = Uuid::uuid4();
            $player->getNetworkSession()->sendDataPacket(PlayerListPacket::add([PlayerListEntry::createAdditionEntry($uuid, $this->id, $this->getName(), SkinAdapterSingleton::get()->toSkinData($this->skin))]));

            $player->getNetworkSession()->sendDataPacket(AddPlayerPacket::create(
                $uuid,
                $this->getName(),
                $this->getId(),
                "",
                $this->location->asVector3(),
                $this->getMotion(),
                $this->location->pitch,
                $this->location->yaw,
                $this->location->yaw,
                ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet($this->heldItem)),
                GameMode::SURVIVAL,
                $this->getAllNetworkData(),
                new PropertySyncData([], []),
                UpdateAbilitiesPacket::create(new AbilitiesData(CommandPermissions::NORMAL, PlayerPermissions::VISITOR, $this->getId(), [
                    new AbilitiesLayer(
                        AbilitiesLayer::LAYER_BASE,
                        array_fill(0, AbilitiesLayer::NUMBER_OF_ABILITIES, false),
                        0.0,
                        0.0
                    )
                ])),
                [],
                "",
                DeviceOS::UNKNOWN
            ));

            $this->sendData([$player], [EntityMetadataProperties::NAMETAG => new StringMetadataProperty($this->getNameTag())]);

            $player->getNetworkSession()->sendDataPacket(PlayerListPacket::remove([PlayerListEntry::createRemovalEntry($uuid)]));
        } else {
            $player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
                $this->getId(),
                $this->getId(),
                $this->networkId,
                $this->location->asVector3(),
                $this->getMotion(),
                $this->location->pitch,
                $this->location->yaw,
                $this->location->yaw,
                $this->location->yaw,
                array_map(function (Attribute $attr): NetworkAttribute {
                    return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), []);
                }, $this->attributeMap->getAll()),
                $this->getAllNetworkData(),
                new PropertySyncData([], []),
                []
            ));
        }
        $player->getNetworkSession()->onMobArmorChange($this);
        $player->getNetworkSession()->sendDataPacket(MobEquipmentPacket::create($this->getId(), ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet($this->offhandItem)), 0, 0, ContainerIds::OFFHAND));
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->knockbackTicks > 0) {
            $this->knockbackTicks--;
        }
        if ($this->isMinion && $this->despawnAfter > 0 && $this->ticksLived >= $this->despawnAfter) {
            $this->flagForDespawn();
        }
        if ($this->isAlive()) {
            $player = $this->getTargetEntity();
            if (!$player instanceof Living && $this->autoAttack) {
                $dist = $this->range * $this->range;
                foreach ($this->getViewers() as $p) {
                    if (!$p->isSpectator() && $p->isAlive() && ($d = $p->location->distanceSquared($this->location)) < $dist) {
                        $player = $p;
                        $dist = $d;
                    }
                }
                $this->setTargetEntity($player);
            }
            if ($player instanceof Living && $player->getWorld() === $this->getWorld() && $player->isAlive() && !$player->isClosed()) {
                if ($this->location->distance($this->spawnPos) > $this->range) {
                    if ($this->isMinion) {
                        $this->flagForDespawn();
                    } else {
                        $this->setPosition($this->spawnPos);
                        $this->setHealth($this->getMaxHealth());
                        $this->setScoreTag("");
                        $this->setTargetEntity(null);
                    }
                } else {
                    if (!$this->gravityEnabled) {
                        $this->resetFallDistance();
                    }
                    if (!$this->onGround && $this->gravityEnabled) {
                        if ($this->motion->y > -$this->gravity * 4) {
                            $this->motion->y = -$this->gravity * 4;
                        } else {
                            $this->motion->y -= $this->gravity;
                        }
                    }
                    if ($this->knockbackTicks <= 0) {
                        $x = $player->location->x - $this->location->x;
                        $y = $player->location->y - $this->location->y;
                        $z = $player->location->z - $this->location->z;
                        if ($x ** 2 + $z ** 2 < 0.7) {
                            $this->motion->x = 0;
                            $this->motion->z = 0;
                        } else {
                            $diff = abs($x) + abs($z);
                            $this->motion->x = $this->speed * 0.15 * ($x / $diff);
                            if (!$this->gravityEnabled) {
                                $this->motion->y = $this->speed * 0.15 * ($y / $diff);
                            } elseif ($this->onGround && $this->movesByJumping) {
                                $this->motion->y = $this->jumpVelocity;
                            }
                            $this->motion->z = $this->speed * 0.15 * ($z / $diff);
                        }
                        $this->location->yaw = rad2deg(atan2(-$x, $z));
                        if ($this->networkId === EntityIds::ENDER_DRAGON) {
                            $this->location->yaw += 180;
                        }
                        $this->location->pitch = rad2deg(atan(-$y));
                        $this->move($this->motion->x, $this->motion->y, $this->motion->z);
                        if ($this->isCollidedHorizontally) {
                            //$this->jump();
                            $this->motion->y = ($this->gravityEnabled ? 0 : mt_rand(0, 1)) === 0 ? $this->jumpVelocity : -$this->jumpVelocity;
                        }
                        $dist = $this->location->distance($player->location);
                        foreach ($this->projectileOptions as $id => $projectileOptions) {
                            if (!empty($projectileOptions["networkId"]) || !empty($projectileOptions["particle"])) {
                                if (!isset($this->projectileDelay[$id])) {
                                    $this->projectileDelay[$id] = 0;
                                }
                                if ($dist >= $projectileOptions["fireRangeMin"] && $dist <= $projectileOptions["fireRangeMax"] && $this->projectileDelay[$id] > $projectileOptions["attackRate"]) {
                                    $this->projectileDelay[$id] = 0;
                                    $projectile = new BossProjectile(Location::fromObject(
                                        $this->getEyePos(),
                                        $this->getWorld(),
                                        ($this->location->yaw > 180 ? 360 : 0) - $this->location->yaw,
                                        -$this->location->pitch
                                    ), $this);
                                    $projectile->setMotion($player->getEyePos()->subtractVector($this->getEyePos())->normalize()->multiply((float)$projectileOptions["speed"]));
                                    $projectile->setData($projectileOptions);
                                    $projectile->spawnToAll();
                                }
                                $this->projectileDelay[$id]++;
                            }
                        }
                        if ($this->attackDelay > $this->attackRate) {
                            if ($player->location->distance($this->location) < $this->attackRange) {
                                $this->attackDelay = 0;
                                $ev = new EntityDamageByEntityEvent($this, $player, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->attackDamage);
                                $player->attack($ev);
                                $this->broadcastAnimation(new ArmSwingAnimation($this));
                            }
                            /* bounding box seems to keep expanding... weird
                             * foreach ($this->getWorld()->getNearbyEntities($this->boundingBox, $this) as $attack) {
                                if ($attack instanceof Living) {
                                    $this->attackDelay = 0;
                                    $ev = new EntityDamageByEntityEvent($this, $attack, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->attackDamage);
                                    $player->attack($ev);
                                    break;
                                }
                            }*/
                        }
                        $this->attackDelay++;
                    } else {
                        $this->move($this->motion->x, $this->motion->y, $this->motion->z);
                    }

                    foreach ($this->minionOptions as $id => $option) {
                        if (!isset($this->minionSpawnDelay[$id])) {
                            $this->minionSpawnDelay[$id] = 0;
                        }
                        if ($this->minionSpawnDelay[$id] > $option["spawnInterval"]) {
                            $this->minionSpawnDelay[$id] = 0;
                            $this->spawnMinion($id, $option);
                        }
                        $this->minionSpawnDelay[$id]++;
                    }
                }
            } else {
                if ($this->isMinion) {
                    $this->flagForDespawn();
                } else {
                    $this->setPosition($this->spawnPos);
                    $this->setHealth($this->getMaxHealth());
                    $this->setScoreTag("");
                    $this->setTargetEntity(null);
                }
            }
            $this->updateMovement();
        }
        parent::onUpdate($currentTick);
        return !$this->closed;
    }

    /**
     * @param int $id
     * @param float[]|bool[]|string[] $option
     * @return Boss|null
     */
    public function spawnMinion(int $id, array $option): ?Boss
    {
        $minion = $this->plugin->spawnBoss((string)$option["name"]);
        if ($minion instanceof Boss) {
            try {
                $data = $this->plugin->data->get((string)$option["name"]);
                $data["x"] = $this->location->x + mt_rand(-(int)$option["spawnRange"], (int)$option["spawnRange"]);
                $data["y"] = $this->location->y;
                $data["z"] = $this->location->z + mt_rand(-(int)$option["spawnRange"], (int)$option["spawnRange"]);
                $data["world"] = $this->getWorld()->getFolderName();
                $data["autoAttack"] = true;
                $data["despawnAfter"] = $option["despawnAfter"] ?? self::MINIONS_OPTIONS_DEFAULT["despawnAfter"];
                foreach ($option as $o => $d) {
                    if (isset($data[$o])) {
                        $data[$o] = $d;
                    }
                }
                $minion->parseData($data);
            } catch (Exception $e) {
                $this->log(LogLevel::ERROR, "Failed to spawn minion $id: " . $e->getMessage());
                $minion->flagForDespawn();
                return null;
            }
            $minion->minionId = $id;
            $minion->isMinion = true;
            $minion->teleport($minion->spawnPos);
            $minion->spawnToAll();
            return $minion;
        } else {
            $this->log(LogLevel::WARNING, "Failed to spawn minion $id: $minion");
        }
        return null;
    }

    public function attack(EntityDamageEvent $source): void
    {
        if (isset($this->hurtModifiers[$source->getCause()])) {
            $source->setBaseDamage($source->getBaseDamage() * $this->hurtModifiers[$source->getCause()]);
        }
        parent::attack($source);
        if (!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent) {
            if (strlen($this->displayHealth)) {
                $length = 20;
                $green = (int)($this->getHealth() / $this->getMaxHealth() * $length);
                $this->setScoreTag(
                    str_replace(
                        ["{HEALTH}", "{MAX_HEALTH}", "{BAR}"],
                        [$this->getHealth(), $this->getMaxHealth(), str_repeat('|', $green) . TextFormat::GRAY . str_repeat('|', $length - $green)],
                        $this->displayHealth
                    )
                );
            }
            $dmg = $source->getDamager();
            if ($dmg instanceof Player) {
                $this->setTargetEntity($dmg);
                $this->knockbackTicks = 10;
                $this->topDamage[$dmg->getName()] = $source->getFinalDamage() + ($this->topDamage[$dmg->getName()] ?? 0);
            }
        }
    }

    public function kill(): void
    {
        parent::kill();
        $player = null;
        if ($this->lastDamageCause instanceof EntityDamageByEntityEvent && $this->lastDamageCause->getDamager() instanceof Player) {
            $player = $this->lastDamageCause->getDamager();
        }
        $this->plugin->executeCommands($this, $player);
        arsort($this->topDamage);
        foreach ($this->topRewards as $topX => $rewards) {
            $i = 0;
            foreach ($this->topDamage as $name => $damage) {
                if ($i++ === $topX) {
                    foreach ($rewards as $reward) {
                        $player = $this->server->getPlayerExact($name);
                        if ($reward instanceof Item) {
                            $player?->getInventory()->addItem($reward);
                        } else {
                            $this->plugin->executeCommands($this, $player, [$reward]);
                        }
                    }
                    break;
                }
            }
        }
        if (!$this->isMinion && $this->respawnTime >= 0) {
            $this->plugin->respawn($this->getName(), $this->respawnTime);
        }
    }

    protected function onDeath(): void
    {
        if ($this->spreadDrops) {
            $id = Entity::nextRuntimeId() + 1;
        }
        parent::onDeath();
        if (isset($id)) {
            $now = Entity::nextRuntimeId();
            for ($i = $id; $i < $now; $i++) {
                $e = $this->getWorld()->getEntity($i);
                if ($e instanceof ItemEntity || $e instanceof ExperienceOrb) {
                    $e->setMotion($e->getMotion()->multiply(3));
                }
            }
        }
    }

    public function getXpDropAmount(): int
    {
        return $this->xpDropAmount;
    }

    /**
     * @return Item[]
     */
    public function getDrops(): array
    {
        /** @var Item[] $drops */
        $drops = array();
        foreach ($this->drops as $drop) {
            if (mt_rand(1, 100) <= $drop->getChance()) {
                $drops[] = $drop->getItem();
            }
        }
        return $drops;
    }

    public function destroyCycles(): void
    {
        parent::destroyCycles();
        unset($this->plugin);
        unset($this->spawnPos);
        unset($this->drops);
        unset($this->heldItem);
    }

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo($this->scale, $this->scale);
    }

    public static function getNetworkTypeId(): string
    {
        return "Boss";
    }

    public function getOffsetPosition(Vector3 $vector3): Vector3
    {
        return $this->networkId === EntityIds::PLAYER ? $vector3->add(0, 1.621, 0) : parent::getOffsetPosition($vector3);
    }
}
