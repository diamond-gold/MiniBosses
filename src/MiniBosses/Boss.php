<?php

namespace MiniBosses;

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
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\SkinAdapterSingleton;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use TypeError;

class Boss extends Living
{

    private ?Main $plugin = null;
    public ?Living $target = null;
    public Position $spawnPos;
    public float $attackDamage, $speed, $range;
    public string $networkId;
    public int $attackRate, $attackDelay = 0, $respawnTime, $knockbackTicks = 0,$attackRange;
    /** @var Item[][]|int[][] */
    public array $drops = array();
    public ?Skin $skin = null;
    public Item $heldItem, $offhandItem;
    public bool $autoAttack;
    public float $width, $height;
    public bool $spreadDrops;
    public int $xpDropAmount;
    public array $projectileOptions = [];
    public array $hurtModifiers = [];
    public array $minionOptions = [];
    public array $minionSpawnDelay = [];
    public bool $isMinion = false;
    public int $minionId = -1;
    public array $topRewards = [];
    public array $topDamage = [];
    public string $displayHealth;

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
    ];

    const PROJECTILE_OPTIONS_DEFAULT = [
        "networkId" => EntityIds::ARROW,
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
        "gravity" => 0.04
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
        "drops" => "1;0;1;;100 2;0;1;;50 3;0;1;;25",
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
        "commands" => ["CONSOLE say {BOSS} killed by {PLAYER}", "OP say {BOSS}"],
        "projectile" => self::PROJECTILE_OPTIONS_DEFAULT,
        "armor" => [],
        "hurtModifiers" => [EntityDamageEvent::CAUSE_ENTITY_ATTACK => 1, EntityDamageEvent::CAUSE_PROJECTILE => 0.2, EntityDamageEvent::CAUSE_FALL => 0],
        "knockbackResistance" => 0,
        "minions" => [],
        "topRewards" => [["item 1;0;1;","command CONSOLE say [{BOSS}] Top Damage by {PLAYER}"]]
    ];

    public function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);
        $this->setCanSaveWithChunk(false);
        $this->plugin = $this->server->getPluginManager()->getPlugin("MiniBosses");
        if(!$this->plugin){
            $this->flagForDespawn();
            return;
        }
        if ($this->plugin->isDisabled()) {
            $this->flagForDespawn();
            $this->log(LogLevel::ERROR,"Despawn due to plugin disabled");
            return;
        }
        $data = $this->plugin->data->get($this->getName());
        if (!$data) {
            $this->flagForDespawn();
            $this->log(LogLevel::ERROR,"Despawn due to no data");
            return;
        }
        try {
            $this->parseData($data);
        } catch (Exception $e) {
            $this->flagForDespawn();
            $this->log(LogLevel::ERROR,"Despawn due to invalid data for boss: ".$e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    private function parseData(array $data,bool $validateMinions = true)
    {
        $this->width = $this->validateType($data,"width","double");
        $this->height = $this->validateType($data,"height","double");
        $this->setSize(new EntitySizeInfo($this->height, $this->width));
        $this->setScale($this->scale = $this->validateType($data,"scale","double"));
        $this->networkId = $this->validateType($data,"networkId","string");
        $this->range = $this->validateType($data,"range","integer");
        $this->spawnPos = new Position(
            $this->validateType($data,"x","double"),
            $this->validateType($data,"y","double"),
            $this->validateType($data,"z","double"),
            $this->server->getWorldManager()->getWorldByName($this->validateType($data,"world","string"))
        );
        $this->attackDamage = $this->validateType($data,"attackDamage","double");
        $this->attackRate = $this->validateType($data,"attackRate","double");
        $this->attackRange = $this->validateType($data,"attackRange","double",$this->scale * $this->width / 2 + 1);
        $this->speed = $this->validateType($data,"speed","double");
        $this->drops = [];
        $drops = $this->validateType($data,"drops","string");
        if ($drops !== "") {
            foreach (explode(' ', $drops) as $itemStr) {
                $explode = explode(';', $itemStr);
                $this->drops[] = [$this->parseItem($itemStr), $explode[4] ?? 100];
            }
        }
        $this->respawnTime = $this->validateType($data,"respawnTime","integer");
        $this->heldItem = $this->parseItem($this->validateType($data,"heldItem","string"));
        $this->offhandItem = $this->parseItem($this->validateType($data,"offhandItem","string"));
        if ($this->networkId === EntityIds::PLAYER) {
            try{
                if (is_string($data["skin"])) {//old data
                    $this->skin = new Skin(Uuid::uuid4()->toString(), $data["skin"]);
                } else {
                    $this->validateType($data,"skin","array");
                    $geometryData = json_decode($data["skin"]["GeometryData"]);
                    if($geometryData === null)
                        $geometryData = hex2bin($data["skin"]["GeometryData"]);
                    $this->skin = new Skin($data["skin"]["Name"], hex2bin($data["skin"]["Data"]), hex2bin($data["skin"]["CapeData"]), $data["skin"]["GeometryName"], $geometryData);
                }
            }catch (Exception $e){
                $this->log(LogLevel::ERROR,"Invalid skin: ".$e->getMessage());
                $this->flagForDespawn();
            }
        }
        $this->autoAttack = $this->validateType($data,"autoAttack","boolean");
        $this->setImmobile();
        $this->setNameTagAlwaysVisible(true);
        $this->setNameTagVisible(true);
        if (isset($data["health"])) {
            $this->validateType($data,"health","double");
            $this->setMaxHealth($data["health"]);
            $this->setHealth($data["health"]);
        }
        $this->jumpVelocity = $this->validateType($data,"jumpStrength","double");
        $this->gravity = $this->validateType($data,"gravity","double");
        $this->gravityEnabled = $this->gravity != 0;
        $this->spreadDrops = $this->validateType($data,"spreadDrops","boolean");
        $this->xpDropAmount = $this->validateType($data,"xpDrop","integer");
        $this->projectileOptions = $this->validateType($data,"projectile","array");
        foreach (self::PROJECTILE_OPTIONS_TYPE as $option => $type){
            $this->projectileOptions[$option] = $this->validateType($this->projectileOptions,$option,$type,self::PROJECTILE_OPTIONS_DEFAULT[$option] ?? null);
        }
        if(!str_starts_with($this->projectileOptions["networkId"],"minecraft:"))
            $this->projectileOptions["networkId"] = "minecraft:".$this->projectileOptions["networkId"];
        $constants = (new ReflectionClass(EntityIds::class))->getConstants();
        if (!in_array($this->projectileOptions["networkId"], $constants, true))
            throw new Exception("Unknown projectile entity type ".$this->projectileOptions["networkId"]);
        if($this->projectileOptions["networkId"] === EntityIds::PLAYER)
            throw new Exception(EntityIds::PLAYER . " is not a valid projectile entity type, please use other entity");
        foreach ($this->validateType($data,"armor","array") as $i => $piece) {
            if (!is_int($i) || !$this->getArmorInventory()->slotExists($i)) {
                $this->log(LogLevel::ERROR,"Invalid slot $i for armor, skipping");
                continue;
            }
            $item = $this->parseItem($piece);
            $this->getArmorInventory()->setItem($i, $item);
        }
        $this->hurtModifiers = $this->validateType($data,"hurtModifiers","array");
        $damageCauses = array_filter((new ReflectionClass(EntityDamageEvent::class))->getConstants(),function ($value,$key):bool{
            return str_contains($key,"CAUSE_");
        },ARRAY_FILTER_USE_BOTH);
        foreach ($this->hurtModifiers as $cause => $multiplier){
            if(!in_array($cause,$damageCauses)){
                unset($this->hurtModifiers[$cause]);
                $this->log(LogLevel::ERROR,"hurtModifiers: Unknown damage cause ".$cause.", skipping ");
                continue;
            }
            if(!is_float($multiplier) && !is_int($multiplier)){
                unset($this->hurtModifiers[$cause]);
                $this->log(LogLevel::ERROR,"hurtModifiers: Invalid multiplier for cause ".$cause.", skipping");
            }
        }
        $this->knockbackResistanceAttr->setValue($this->validateType($data,"knockbackResistance","double"));
        $this->minionOptions = $this->validateType($data,"minions","array");
        $this->topRewards = $this->validateType($data,"topRewards", "array");
        foreach ($this->topRewards as $top => $rewards){
            if(is_array($rewards)){
                foreach ($rewards as $i => $rewardStr){
                    $r = explode(' ',$rewardStr);
                    switch (strtolower($r[0])){
                        case "item":
                            $this->topRewards[$top][$i] = $this->parseItem(substr($rewardStr,strlen($r[0]) + 1));
                            break;
                        case "command":
                            $this->topRewards[$top][$i] = substr($rewardStr,strlen($r[0]) + 1);
                            break;
                        default:
                            unset($this->topRewards[$top][$i]);
                            $this->log(LogLevel::ERROR,"topRewards: Unknown reward $rewardStr, skipping");
                            break;
                    }
                }
            }
        }
        $this->displayHealth = $this->validateType($data,"displayHealth","string");
        if($validateMinions) {
            foreach ($this->minionOptions as $id => $minionData) {
                if (!is_int($id))
                    throw new Exception("Minion $id error: Minion id must be an integer");
                try{
                    foreach (["name" => "string", "spawnInterval" => "integer", "spawnRange" => "double"] as $option => $type) {
                        $this->validateType($minionData, $option, $type);
                    }
                    $testMinionData = array_merge($minionData,["x" => 0,"y" => 0,"z" => 0,"world" => "","networkId" => EntityIds::PIG]);//dummy data that should be supplied by boss
                    $this->parseData($testMinionData);
                    if($minionData['spawnRange'] > $this->range)
                        $this->log(LogLevel::WARNING,"Minion $id has spawnRange (".$minionData['spawnRange'].") that is larger than its range ($this->range), it will immediately despawn if there are no players in it's range");
                }catch (Exception $e){
                    throw new Exception("Minion $id error: " . $e->getMessage());
                }
            }
            $this->parseData($data, false);
        }
    }

    /**
     * Throws Exception if data is not expected type and no default value
     * @throws Exception
     */
    private function validateType(array $data, string $index, string $type, mixed $defaultOverride = null){
        $default = $defaultOverride ?? self::BOSS_OPTIONS_DEFAULT[$index] ?? null;
        if(!isset($data[$index])) {
            if($default === null)
                throw new SavedDataLoadingException("Missing required data \"$index\" of type $type");
            return $default;
        }
        $dataType = gettype($data[$index]);
        if($dataType === $type || ($dataType === "integer" && $type === "double"))
            return $data[$index];
        if($type === "double") $type = "integer/float/double";
        throw new SavedDataLoadingException("\"$index\" must be $type, $dataType given");
    }

    private function parseItem(string $itemStr): Item{
        if($itemStr === "")
            return VanillaItems::AIR();
        $item = explode(";",$itemStr);
        try {
            return ItemFactory::getInstance()->get($item[0], empty($item[1]) ? 0 : $item[1], empty($item[2]) ? 1 : $item[2], !empty($item[3]) ? (new LittleEndianNbtSerializer())->read(hex2bin($item[3]))->mustGetCompoundTag() : null);
        }catch (Exception|TypeError $e){
            $this->log(LogLevel::ERROR,"Failed to parse item $itemStr: ".$e->getMessage());
        }
        return VanillaItems::AIR();
    }

    protected function log(string $level,string $msg){
        $this->plugin?->getLogger()->log($level,"[".($this->isMinion ? "Minion$this->minionId ".$this->getName() : $this->getName())."] ".$msg);
    }

    public function getName(): string
    {
        return $this->getNameTag();
    }

    public function setNameTag(string $name): void
    {
        if ($this->getNameTag() === "")
            parent::setNameTag($name);
        else
            $this->plugin?->getLogger()->logException(new Exception("Boss name tag should not be modified"));
    }

    public function sendSpawnPacket(Player $player): void
    {
        if ($this->networkId === EntityIds::PLAYER) {
            $uuid = Uuid::uuid4();

            $player->getNetworkSession()->sendDataPacket(PlayerListPacket::add([PlayerListEntry::createAdditionEntry($uuid, $this->id, $this->getName(), SkinAdapterSingleton::get()->toSkinData($this->skin))]));

            $player->getNetworkSession()->sendDataPacket(AddPlayerPacket::create(
                $uuid,
                $this->getName(),
                $this->getId(),
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
                AdventureSettingsPacket::create(0, 0, 0, 0, 0, $this->getId()),
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
                $this->getMotion(), $this->location->pitch, $this->location->yaw, $this->location->yaw,
                array_map(function (Attribute $attr): NetworkAttribute {
                    return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue());
                }, $this->attributeMap->getAll()),
                $this->getAllNetworkData(),
                []
            ));
        }
        $player->getNetworkSession()->onMobArmorChange($this);
        $player->getNetworkSession()->sendDataPacket(MobEquipmentPacket::create($this->getId(), ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet($this->offhandItem)), 0, 0, ContainerIds::OFFHAND));
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->knockbackTicks > 0) $this->knockbackTicks--;
        if ($this->isAlive()) {
            $player = $this->target;
            if (!$player instanceof Living && $this->autoAttack) {
                $dist = $this->range * $this->range;
                foreach ($this->getViewers() as $p) {
                    if (!$p->isSpectator() && $p->isAlive() && ($d = $p->location->distanceSquared($this->location)) < $dist) {
                        $player = $p;
                        $dist = $d;
                    }
                }
                $this->target = $player;
            }
            if ($player instanceof Living && $player->getWorld() === $this->getWorld() && $player->isAlive() && !$player->isClosed()) {
                if ($this->location->distance($this->spawnPos) > $this->range) {
                    if ($this->isMinion) {
                        $this->flagForDespawn();
                    } else {
                        $this->setPosition($this->spawnPos);
                        $this->setHealth($this->getMaxHealth());
                        $this->setScoreTag("");
                        $this->target = null;
                    }
                } else {
                    if(!$this->gravityEnabled)
                        $this->resetFallDistance();
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
                            if (!$this->gravityEnabled)
                                $this->motion->y = $this->speed * 0.15 * ($y / $diff);
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
                            $this->motion->y = ($this->gravityEnabled ? 0 : mt_rand(0,1)) === 0 ? $this->jumpVelocity : -$this->jumpVelocity;
                        }
                        $dist = $this->location->distance($player->location);
                        if (isset($this->projectileOptions["networkId"])) {
                            if ($dist >= $this->projectileOptions["fireRangeMin"] && $dist <= $this->projectileOptions["fireRangeMax"] && $this->attackDelay > $this->projectileOptions["attackRate"]) {
                                $this->attackDelay = 0;
                                $projectile = new BossProjectile(Location::fromObject(
                                    $this->getEyePos(),
                                    $this->getWorld(),
                                    ($this->location->yaw > 180 ? 360 : 0) - $this->location->yaw,
                                    -$this->location->pitch), $this, CompoundTag::create()->setString("networkId", $this->projectileOptions["networkId"])
                                );
                                $projectile->setMotion($player->getEyePos()->subtractVector($this->getEyePos())->normalize()->multiply($this->projectileOptions["speed"]));
                                $projectile->spawnToAll();
                            }
                        }
                        if ($this->attackDelay > $this->attackRate) {
                            if($this->target->location->distance($this->location) < $this->attackRange){
                                $this->attackDelay = 0;
                                $ev = new EntityDamageByEntityEvent($this, $this->target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->attackDamage);
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
                    } else
                        $this->move($this->motion->x, $this->motion->y, $this->motion->z);

                    foreach ($this->minionOptions as $id => $option) {
                        if (!isset($this->minionSpawnDelay[$id]))
                            $this->minionSpawnDelay[$id] = 0;
                        if ($this->minionSpawnDelay[$id] > $option["spawnInterval"]) {
                            $this->minionSpawnDelay[$id] = 0;
                            $this->spawnMinion($id,$option);
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
                    $this->target = null;
                }
            }
            $this->updateMovement();
        }
        parent::onUpdate($currentTick);
        return !$this->closed;
    }

    function spawnMinion(int $id,array $option): ?Boss{
        $minion = $this->plugin->spawnBoss($option["name"]);
        if($minion instanceof Boss) {
            try {
                $data = $this->plugin->data->get($option["name"]);
                $data["x"] = $this->location->x + mt_rand(-$option["spawnRange"], $option["spawnRange"]);
                $data["y"] = $this->location->y;
                $data["z"] = $this->location->z + mt_rand(-$option["spawnRange"], $option["spawnRange"]);
                $data["world"] = $this->getWorld()->getFolderName();
                $data["autoAttack"] = true;
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
        }else
            $this->log(LogLevel::WARNING,"Failed to spawn minion $id, $minion");
        return null;
    }

    public function attack(EntityDamageEvent $source): void
    {
        if (isset($this->hurtModifiers[$source->getCause()])) {
            $source->setBaseDamage($source->getBaseDamage() * floatval($this->hurtModifiers[$source->getCause()]));
        }
        parent::attack($source);
        if (!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent) {
            if(strlen($this->displayHealth)){
                $length = 20;
                $green = (int)($this->getHealth() / $this->getMaxHealth() * $length);
                $this->setScoreTag(str_replace(
                    ["{HEALTH}","{MAX_HEALTH}","{BAR}"],
                    [$this->getHealth(),$this->getMaxHealth(),str_repeat('|',$green).TextFormat::GRAY.str_repeat('|',$length - $green)],
                    $this->displayHealth)
                );
            }
            $dmg = $source->getDamager();
            if ($dmg instanceof Player) {
                $this->target = $dmg;
                $this->knockbackTicks = 10;
                $this->topDamage[$dmg->getName()] = $source->getFinalDamage() + ($this->topDamage[$dmg->getName()] ?? 0);
            }
        }
    }

    public function kill(): void
    {
        parent::kill();
        if ($this->lastDamageCause instanceof EntityDamageByEntityEvent && $this->lastDamageCause->getDamager() instanceof Player)
            $this->plugin->executeCommands($this, $this->lastDamageCause->getDamager());
        arsort($this->topDamage);
        foreach ($this->topRewards as $topX => $rewards){
            $i = 0;
            foreach ($this->topDamage as $name => $damage){
                if($i++ === $topX){
                    foreach ($rewards as $reward){
                        $player = $this->server->getPlayerExact($name);
                        if($reward instanceof Item)
                            $player?->getInventory()->addItem($reward);
                        else
                            $this->plugin->executeCommands($this,$player,[$reward]);
                    }
                    break;
                }
            }
        }
        if (!$this->isMinion)
            $this->plugin->respawn($this->getName(), $this->respawnTime);
    }

    protected function onDeath(): void
    {
        if($this->spreadDrops)
            $id = Entity::nextRuntimeId() + 1;
        parent::onDeath();
        if(isset($id)) {
            $now = Entity::nextRuntimeId();
            for ($i = $id; $i < $now; $i++) {
                $e = $this->getWorld()->getEntity($i);
                if($e instanceof ItemEntity || $e instanceof ExperienceOrb)
                    $e->setMotion($e->getMotion()->multiply(3));
            }
        }
    }

    public function getXpDropAmount(): int
    {
        return $this->xpDropAmount;
    }

    public function getDrops(): array
    {
        $drops = array();
        foreach ($this->drops as $drop) {
            if (mt_rand(1, 100) <= $drop[1]) $drops[] = $drop[0];
        }
        return $drops;
    }

    public function destroyCycles(): void
    {
        parent::destroyCycles();
        unset($this->target);
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