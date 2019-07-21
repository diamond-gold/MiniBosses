<?php

namespace MiniBosses;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityIds;
use pocketmine\event\Listener;
use pocketmine\level\Position;
use pocketmine\nbt\LittleEndianNBTStream;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;

class Main extends PluginBase implements Listener{

	/** @var Config */
	public $data;
	const NetworkIds = array(
		"chicken"=>EntityIds::CHICKEN,
		"cow"=>EntityIds::COW,
		"pig"=>EntityIds::PIG,
		"sheep"=>EntityIds::SHEEP,
		"wolf"=>EntityIds::WOLF,
		"villager"=>EntityIds::VILLAGER,
		"mooshroom"=>EntityIds::MOOSHROOM,
		"squid"=>EntityIds::SQUID,
		"rabbit"=>EntityIds::RABBIT,
		"bat"=>EntityIds::BAT,
		"irongolem"=>EntityIds::IRON_GOLEM,
		"snowgolem"=>EntityIds::SNOW_GOLEM,
		"ocelot"=>EntityIds::OCELOT,
		"horse"=>EntityIds::HORSE,
		"donkey"=>EntityIds::DONKEY,
		"mule"=>EntityIds::MULE,
		"skeletonhorse"=>EntityIds::SKELETON_HORSE,
		"zombiehorse"=>EntityIds::ZOMBIE_HORSE,
		"zombie"=>EntityIds::ZOMBIE,
		"creeper"=>EntityIds::CREEPER,
		"skeleton"=>EntityIds::SKELETON,
		"spider"=>EntityIds::SPIDER,
		"pigman"=>EntityIds::ZOMBIE_PIGMAN,
		"slime"=>EntityIds::SLIME,
		"enderman"=>EntityIds::ENDERMAN,
		"silverfish"=>EntityIds::SILVERFISH,
		"cavespider"=>EntityIds::CAVE_SPIDER,
		"ghast"=>EntityIds::GHAST,
		"magmacube"=>EntityIds::MAGMA_CUBE,
		"blaze"=>EntityIds::BLAZE,
		"zombievillager"=>EntityIds::ZOMBIE_VILLAGER,
		"witch"=>EntityIds::WITCH,
		"stray"=>EntityIds::STRAY,
		"husk"=>EntityIds::HUSK,
		"witherskeleton"=>EntityIds::WITHER_SKELETON,
		"guardian"=>EntityIds::GUARDIAN,
		"elderguardian"=>EntityIds::ELDER_GUARDIAN,
		"wither"=>EntityIds::WITHER,
		"enderdragon"=>EntityIds::ENDER_DRAGON,
		"shulker"=>EntityIds::SHULKER,
		"endermite"=>EntityIds::ENDERMITE,
		"human"=>EntityIds::PLAYER,
		"vindicator"=>EntityIds::VINDICATOR,
		"phantom"=>EntityIds::PHANTOM,
		"armorstand"=>EntityIds::ARMOR_STAND,
		"pufferfish"=>EntityIds::PUFFERFISH,
		"salmon"=>EntityIds::SALMON,
		"tropicalfish"=>EntityIds::TROPICAL_FISH,
		"cod"=>EntityIds::COD,
		"panda"=>EntityIds::PANDA,
	);

	public function onEnable(){
		@mkdir($this->getDataFolder());
		Entity::registerEntity(Boss::class);
		$this->data = new Config($this->getDataFolder()."Bosses.yml",Config::YAML);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}
	
	public function onCommand(CommandSender $sender, Command $cmd,string $label, array $args): bool{
		if(!isset($args[0])){
			$sender->sendMessage("Usage: /minibosses create/spawn/delete/list");
		}elseif($args[0] === "create"){
			if(!($sender instanceof Player)) $sender->sendMessage("Please run in-game");
			elseif(count($args) >= 3){
				$networkid = $args[1];
				array_shift($args);
				array_shift($args);
				$name = implode(' ',$args);
				if($this->data->get($name,null) === null){
					if(is_numeric($networkid) && in_array($networkid, self::NetworkIds)){
						// Do absolutely nothing.
					}elseif(!is_numeric($networkid) && array_key_exists($networkid, self::NetworkIds)){
						$networkid = self::NetworkIds[strtolower($networkid)];
					}else {
						$sender->sendMessage(TF::RED . "Unrecognised Network ID or Entity type $networkid");
						return true;
					}
					$heldItem = $sender->getInventory()->getItemInHand();
					$skin = $sender->getSkin();
					$this->data->set($name,array(
						"network-id" => (int)$networkid,
						"x"=>$sender->x,"y"=>$sender->y,"z"=>$sender->z,"level"=>$sender->level->getName(),
						"health"=>20,"range"=>10,"attackDamage"=>1,"attackRate"=>10,"speed"=>1,
						"drops"=>"1;0;1;;100 2;0;1;;50 3;0;1;;25","respawnTime"=>100,
						"skin"=>["Name"=>$skin->getSkinId(),"Data"=>bin2hex($skin->getSkinData()),"CapeData"=>bin2hex($skin->getCapeData()),"GeometryName"=>$skin->getGeometryName(),"GeometryData"=>bin2hex($skin->getGeometryData())],
						"heldItem"=>($heldItem->getId().";".$heldItem->getDamage().";".$heldItem->getCount().";".(new LittleEndianNBTStream())->write($heldItem->getNamedTag())),
						"scale"=>1,"autoAttack"=>false));
					$this->data->save();
					$this->spawnBoss($name);
					$sender->sendMessage(TF::GREEN . "Successfully created MiniBoss: $name");
				}else $sender->sendMessage(TF::RED . "That MiniBoss already exists!");
			}else $sender->sendMessage(TF::RED . "Usage: /minibosses create network-id name");
		}elseif($args[0] === "spawn"){
			if(count($args) >= 2){
				array_shift($args);
				$name = implode(' ',$args);
				if($this->data->get($name,null) !== null){
					$ret = $this->spawnBoss($name);
					if($ret === true) $sender->sendMessage("Successfully spawned $name");
					else $sender->sendMessage(TF::RED . "Error spawning $name : $ret");
				}else $sender->sendMessage(TF::RED . "That MiniBoss doesn't exist!");
			}else $sender->sendMessage(TF::RED . "Usage: /minibosses spawn name");
		}elseif($args[0] === "delete"){
			if(count($args) >= 2){
				array_shift($args);
				$name = implode($args);
				if(($data = $this->data->get($name,null)) !== null){
					if($this->getServer()->loadLevel($data["level"])){
						$l = $this->getServer()->getLevelByName($data["level"]);
						if($chunk = $l->getChunk($data["x"] >> 4,$data["z"] >> 4)){
							foreach($chunk->getEntities() as $e){
								if($e instanceof Boss && $e->getNameTag() === $name) $e->close();
							}
						}
					}
					$this->data->remove($name);
					$this->data->save();
					$sender->sendMessage(TF::GREEN . "Successfully removed MiniBoss: $name");
				}else $sender->sendMessage(TF::RED . "That MiniBoss doesn't exist!");
			}else $sender->sendMessage(TF::RED . "Usage: /minibosses delete name");
		}elseif($args[0] === "list"){
			$sender->sendMessage(TF::GREEN . "----MiniBosses----");
			$sender->sendMessage(implode(', ',array_keys($this->data->getAll())));
		}else{
			$sender->sendMessage(TF::RED . "Usage: /minibosses create/spawn/delete/list");
		}
		return true;
	}
	
	public function spawnBoss(string $name){
		$data = $this->data->get($name);
		if(!$data) return "No data, Boss does not exist";
		elseif(!$this->getServer()->loadLevel($data["level"]))
			return "Failed to load Level {$data["level"]}";
		$networkId = (int)$data["network-id"];
		$pos = new Position($data["x"],$data["y"],$data["z"],$this->getServer()->getLevelByName($data["level"]));
		$health = $data["health"];
		$range = $data["range"];
		$nbt = Entity::createBaseNBT($pos);
		$nbt->setTag(new ListTag("spawnPos", [
			new DoubleTag("", $pos->x),
			new DoubleTag("", $pos->y),
			new DoubleTag("", $pos->z)
		]));
		$nbt->setFloat("range",$range * $range);
		$nbt->setFloat("attackDamage",$data["attackDamage"]);
		$nbt->setInt("networkId",$networkId);
		$nbt->setInt("attackRate",$data["attackRate"]);
		$nbt->setFloat("speed",$data["speed"]);
		$nbt->setString("drops",$data["drops"]);
		$nbt->setInt("respawnTime",$data["respawnTime"]);
		if(is_string($data["skin"])){//old data
			$skin = ($networkId === EntityIds::PLAYER ? $data["skin"] : "");
			$nbt->setString("skin",$skin);
		}else{
			$nbt->setTag(new CompoundTag("Skin", [
				new StringTag("Name", $data["skin"]["Name"]),
				new ByteArrayTag("Data",hex2bin($data["skin"]["Data"])),
				new ByteArrayTag("CapeData", hex2bin($data["skin"]["CapeData"])),
				new StringTag("GeometryName", $data["skin"]["GeometryName"]),
				new ByteArrayTag("GeometryData", hex2bin($data["skin"]["GeometryData"]))
			]));
		}
		$nbt->setString("heldItem",$data["heldItem"]);
		$nbt->setFloat("scale",$data["scale"] ?? 1);
		$nbt->setByte("autoAttack",$data["autoAttack"]??false);
		$pos->getLevel()->getChunkAtPosition($pos,true);
		$ent = Entity::createEntity("Boss",$pos->getLevel(),$nbt);
		$ent->setMaxHealth($health);
		$ent->setHealth($health);
		$ent->setNameTag($name);
		$ent->setNameTagAlwaysVisible(true);
		$ent->setNameTagVisible(true);
		$ent->spawnToAll();
		return true;
	}
	
	public function respawn(string $name,int $time){
		if($this->data->get($name)) $this->getScheduler()->scheduleDelayedTask(new RespawnTask($this,$name), $time);
	}
}
class RespawnTask extends Task{

	private $plugin,$name;

	public function __construct(Main $plugin,$name){
		$this->plugin = $plugin;
		$this->name = $name;
	}
	
	public function onRun(int $currentTick){
		$this->plugin->spawnBoss($this->name);
	}
}