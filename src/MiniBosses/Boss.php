<?php

namespace MiniBosses;

use pocketmine\entity\Creature;
use pocketmine\entity\EntityIds;
use pocketmine\entity\Living;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\nbt\LittleEndianNBTStream;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\AddEntityPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\Player;
use pocketmine\utils\UUID;

class Boss extends Creature{

	const NETWORK_ID = 1000;
	/** @var Main */
	private $plugin;
	/** @var Living */
	public $target;
	/** @var Position  */
	public $spawnPos;
	/** @var float  */
	public $attackDamage,$speed,$range,$scale;
	/** @var int */
	public $networkId,$attackRate, $attackDelay = 0,$respawnTime,$knockbackTicks = 0;
	/** @var Item[][]|int[][] */
	public $drops = array();
	/** @var Skin */
	public $skin;
	/** @var Item  */
	public $heldItem;

	public function __construct(Level $level,CompoundTag $nbt){
		$this->scale = $nbt->getFloat("scale",1);
		$this->height = $this->width = $this->scale;
		$this->networkId = (int)$nbt->getInt("networkId");
		$this->range = $nbt->getFloat("range",10);
		$spawnPos = $nbt->getListTag("spawnPos")->getAllValues();
		$this->spawnPos = new Position($spawnPos[0],$spawnPos[1],$spawnPos[2],$this->level);
		$this->attackDamage = $nbt->getFloat("attackDamage",1);
		$this->attackRate = $nbt->getInt("attackRate",10);
		$this->speed = $nbt->getFloat("speed",1);
		$drops = $nbt->getString("drops","");
		if($drops !== ""){
			foreach(explode(' ',$drops) as $item){
				$item = explode(';',$item);
				$this->drops[] = [Item::get($item[0],$item[1] ?? 0,$item[2] ?? 1,$item[3] ?? ""),$item[4] ?? 100];
			}
		}
		$this->respawnTime = $nbt->getInt("respawnTime",100);
		$heldItem = $nbt->getString("heldItem","");
		if($heldItem !== ""){
			$heldItem = explode(';',$heldItem);
			$this->heldItem = Item::get($heldItem[0],$heldItem[1] ?? 0,$heldItem[2] ?? 1,$heldItem[3] ?? "");
		}else{
			$this->heldItem = Item::get(Item::AIR);
		}
		if($this->networkId === EntityIds::PLAYER){
			$this->skin = self::deserializeSkinNBT($nbt);
			$this->baseOffset = 1.62;
		}
		parent::__construct($level,$nbt);
	}

	/**
	 * @param CompoundTag $nbt
	 *
	 * @return Skin
	 * @throws \InvalidArgumentException
	 */
	private function deserializeSkinNBT(CompoundTag $nbt) : Skin{
		if($nbt->hasTag("skin",StringTag::class)){
			$skin = new Skin(mt_rand(-PHP_INT_MAX,PHP_INT_MAX)."_Custom",$nbt->getString("skin",""));
		}else{
			$skinTag = $nbt->getCompoundTag("Skin");
			$skin = new Skin(
				$skinTag->getString("Name"),
				$skinTag->hasTag("Data", StringTag::class) ? $skinTag->getString("Data") : $skinTag->getByteArray("Data"), //old data (this used to be saved as a StringTag in older versions of PM)
				$skinTag->getByteArray("CapeData", ""),
				$skinTag->getString("GeometryName", ""),
				$skinTag->getByteArray("GeometryData", "")
			);
		}
		$skin->validate();
		return $skin;
	}

	public function initEntity(): void{
		$this->plugin = $this->server->getPluginManager()->getPlugin("MiniBosses");
        parent::initEntity();
        $this->setImmobile();
        $this->setScale($this->namedtag->getFloat("scale"));
		if($this->namedtag->hasTag("maxHealth",IntTag::class)){
			$health = (int)$this->namedtag->getInt("maxHealth");
			parent::setMaxHealth($health);
			$this->setHealth($health);
		}else{
			$this->setMaxHealth(20);
			$this->setHealth(20);
		}
    }

	public function getName(): string{
		return $this->getNameTag();
	}

	public function sendSpawnPacket(Player $player): void{
		if($this->networkId === EntityIds::PLAYER){
			$uuid = UUID::fromData($this->getId(), $this->skin->getSkinData(), $this->getName());
			$pk = new PlayerListPacket();
			$pk->type = PlayerListPacket::TYPE_ADD;
			$pk->entries = [PlayerListEntry::createAdditionEntry($uuid, $this->id, $this->getName(), $this->skin)];
			$player->dataPacket($pk);
			$pk = new AddPlayerPacket();
			$pk->uuid = $uuid;
			$pk->username = $this->getName();
			$pk->entityRuntimeId = $this->getId();
			$pk->position = $this->asVector3();
			$pk->motion = $this->getMotion();
			$pk->yaw = $this->yaw;
			$pk->pitch = $this->pitch;
			$pk->item = $this->heldItem;
			$pk->metadata = $this->getDataPropertyManager()->getAll();
			$player->dataPacket($pk);
			$this->sendData($player, [self::DATA_NAMETAG => [self::DATA_TYPE_STRING, $this->getName()]]);//Hack for MCPE 1.2.13: DATA_NAMETAG is useless in AddPlayerPacket, so it has to be sent separately
			$pk = new PlayerListPacket();
			$pk->type = PlayerListPacket::TYPE_REMOVE;
			$pk->entries = [PlayerListEntry::createRemovalEntry($uuid)];
			$player->dataPacket($pk);
		}else{
			$pk = new AddEntityPacket();
			$pk->entityRuntimeId = $this->getID();
			$pk->type = $this->networkId;
			$pk->position = $this->asVector3();
			$pk->motion = $this->getMotion();
			$pk->yaw = $this->yaw;
			$pk->pitch = $this->pitch;
			$pk->metadata = $this->getDataPropertyManager()->getAll();
			$player->dataPacket($pk);
			if(!$this->heldItem->isNull()){
				$pk = new MobEquipmentPacket();
				$pk->entityRuntimeId = $this->getId();
				$pk->item = $this->heldItem;
				$pk->inventorySlot = 0;
				$pk->hotbarSlot = 0;
				$player->dataPacket($pk);
			}
		}
	}

	public function setMaxHealth(int $health): void{
		$this->namedtag->setInt("maxHealth",$health);
		parent::setMaxHealth($health);
	}

	public function saveNBT():void {
        parent::saveNBT();
		$this->namedtag->setInt("maxHealth",$this->getMaxHealth());
		$this->namedtag->setTag(new ListTag("spawnPos", [
			new DoubleTag("", $this->spawnPos->x),
			new DoubleTag("", $this->spawnPos->y),
			new DoubleTag("", $this->spawnPos->z)
		]));
		$this->namedtag->setFloat("range",$this->range);
		$this->namedtag->setFloat("attackDamage",$this->attackDamage);
		$this->namedtag->setInt("networkId",$this->networkId);
		$this->namedtag->setInt("attackRate",$this->attackRate);
		$this->namedtag->setFloat("speed",$this->speed);
		$drops2 = [];
		foreach($this->drops as $drop)
			$drops2[] = $drop[0]->getId().";".$drop[0]->getDamage().";".$drop[0]->getCount().";".(new LittleEndianNBTStream())->write($drop[0]->getNamedTag()).";".$drop[1];
		$this->namedtag->setString("drops",implode(' ',$drops2));
		$this->namedtag->setInt("respawnTime",$this->respawnTime);
		if($this->skin !== null){
			$this->namedtag->setTag(new CompoundTag("Skin", [
				new StringTag("Name", $this->skin->getSkinId()),
				new ByteArrayTag("Data", $this->skin->getSkinData()),
				new ByteArrayTag("CapeData", $this->skin->getCapeData()),
				new StringTag("GeometryName", $this->skin->getGeometryName()),
				new ByteArrayTag("GeometryData", $this->skin->getGeometryData())
			]));
		}
		$this->namedtag->removeTag("skin");//old data
		$this->namedtag->setString("heldItem",($this->heldItem instanceof Item ? $this->heldItem->getId().";".$this->heldItem->getDamage().";".$this->heldItem->getCount().";".(new LittleEndianNBTStream())->write($this->heldItem->getNamedTag()) : ""));
		$this->namedtag->setFloat("scale", $this->scale);
	}

	public function onUpdate(int $currentTick): bool {
		if($this->knockbackTicks > 0) $this->knockbackTicks--;
		if($this->isAlive()){
			$player = $this->target;
			if($player instanceof Living && $player->isAlive() && !$player->isClosed()){
				if($this->distanceSquared($this->spawnPos) > $this->range){
					$this->setPosition($this->spawnPos);
					$this->setHealth($this->getMaxHealth());
					$this->target = null;
				} else{
					if(!$this->onGround){
						if($this->motion->y > -$this->gravity * 4){
							$this->motion->y = -$this->gravity * 4;
						} else{
							$this->motion->y -= $this->gravity;
						}
						$this->move($this->motion->x, $this->motion->y, $this->motion->z);
					} elseif($this->knockbackTicks > 0){

					} else{
						$x = $player->x - $this->x;
						$y = $player->y - $this->y;
						$z = $player->z - $this->z;
						if($x ** 2 + $z ** 2 < 0.7){
							$this->motion->x = 0;
							$this->motion->z = 0;
						} else{
							$diff = abs($x) + abs($z);
							$this->motion->x = $this->speed * 0.15 * ($x / $diff);
							$this->motion->z = $this->speed * 0.15 * ($z / $diff);
						}
						$this->yaw = rad2deg(atan2(-$x, $z));
						if($this->networkId === EntityIds::ENDER_DRAGON){
							$this->yaw += 180;
						}
						$this->pitch = rad2deg(atan(-$y));
						$this->move($this->motion->x, $this->motion->y, $this->motion->z);
						if($this->distanceSquared($this->target) < $this->scale && $this->attackDelay++ > $this->attackRate){
							$this->attackDelay = 0;
							$ev = new EntityDamageByEntityEvent($this, $this->target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->attackDamage);
							$player->attack($ev);
						}
					}
				}
			}else{
				$this->setPosition($this->spawnPos);
				$this->setHealth($this->getMaxHealth());
				$this->target = null;
			}
			$this->updateMovement();
		}
		parent::onUpdate($currentTick);
		return !$this->closed;
	}

	public function attack(EntityDamageEvent $source): void{
		if(!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent){
			$dmg = $source->getDamager();
			if($dmg instanceof Player){
				parent::attack($source);
				if(!$source->isCancelled()){
					$this->target = $dmg;
					$this->motion->x = ($this->x - $dmg->x) * 0.19;
					$this->motion->y = 0.5;
					$this->motion->z = ($this->z - $dmg->z) * 0.19;
					$this->knockbackTicks = 10;
				}
			}
        }
    }

	public function kill():void {
		parent::kill();
		$this->plugin->respawn($this->getNameTag(),$this->respawnTime);
	}

	public function getDrops():array {
		$drops = array();
		foreach($this->drops as $drop){
			if(mt_rand(1,100) <= $drop[1]) $drops[] = $drop[0];
		}
		return $drops;
	}

	public function close(): void
	{
		parent::close();
		$this->plugin = null;
		$this->spawnPos = null;
		$this->drops = [];
		$this->heldItem = null;
	}
}