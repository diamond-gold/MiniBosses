<?php

namespace MiniBosses;

use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\level\Level;
use pocketmine\scheduler\PluginTask;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\nbt\NBT;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class Main extends PluginBase implements Listener{
	
	const NetworkIds = array("chicken"=>10,
							"cow"=>11,
							"pig"=>12,
							"sheep"=>13,
							"wolf"=>14,
							"villager"=>15,
							"mooshroom"=>16,
							"squid"=>17,
							"rabbit"=>18,
							"bat"=>19,
							"irongolem"=>20,
							"snowgolem"=>21,
							"ocelot"=>22,
							"horse"=>23,
							"donkey"=>24,
							"mule"=>25,
							"skeletonhorse"=>26,
							"zombiehorse"=>27,
							"zombie"=>32,
							"creeper"=>33,
							"skeleton"=>34,
							"spider"=>35,
							"pigman"=>36,
							"slime"=>37,
							"enderman"=>38,
							"silverfish"=>39,
							"cavespider"=>40,
							"magmacube"=>42,
							"blaze"=>43,
							"zombievillager"=>44,
							"witch"=>45,
							"stray"=>46,
							"husk"=>47,
							"witherskeleton"=>48,
							"human"=>63);

	public function onEnable(){
		@mkdir($this->getDataFolder());
		Entity::registerEntity(Boss::class);
		$this->data = new Config($this->getDataFolder()."Bosses.yml",Config::YAML);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}
	
	public function onCommand(CommandSender $sender, Command $cmd, $label, array $args){
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
					if(($search = array_search($networkid,self::NetworkIds,true)) === false && !isset(self::NetworkIds[strtolower($networkid)])){
						$sender->sendMessage("Unrecognised Network ID or Entity type $networkid");
						return true;
					}else{
						if($search === false) $networkid = self::NetworkIds[strtolower($networkid)];
					}
					$heldItem = $sender->getInventory()->getItemInHand();
					$this->data->set($name,array("network-id" => $networkid,"x"=>$sender->x,"y"=>$sender->y,"z"=>$sender->z,"level"=>$sender->level->getName(),"health"=>20,"range"=>10,"attackDamage"=>1,"attackRate"=>10,"speed"=>1,"drops"=>"1;2;3 4;5;6 7;8;9","respawnTime"=>100,"skin"=>($networkid === 63 ? bin2hex($sender->getSkinData()) : ""),"heldItem"=>($networkid === 63 ? $heldItem->getId().";".$heldItem->getDamage().";".$heldItem->getCount().";" : "")));
					$this->data->save();
					$this->spawnBoss($name);
					$sender->sendMessage("Successfully created $name");
				}else $sender->sendMessage("Already exists");
			}else $sender->sendMessage("Usage: /minibosses create network-id name");
		}elseif($args[0] === "spawn"){
			if(count($args) >= 2){
				array_shift($args);
				$name = implode(' ',$args);
				if($this->data->get($name,null) !== null){
					$this->spawnBoss($name);
					$sender->sendMessage("Successfully spawned $name");
				}else $sender->sendMessage("Not exist");
			}else $sender->sendMessage("Usage: /minibosses spawn name");
		}elseif($args[0] === "delete"){
			if(count($args) >= 2){
				array_shift($args);
				$name = implode($args);
				if($this->data->get($name,null) !== null){
					$this->data->remove($name);
					$this->data->save();
					$sender->sendMessage("Successfully removed $name");
					foreach($this->getServer()->getLevels() as $l){
						foreach($l->getEntities() as $e){
							if($e instanceof Boss && $e->getNameTag() === $name) $e->close();
						}
					}
				}else $sender->sendMessage("Not exist");
			}else $sender->sendMessage("Usage: /minibosses delete name");
		}elseif($args[0] === "list"){
			$sender->sendMessage("----MiniBosses----");
			$sender->sendMessage(implode(', ',array_keys($this->data->getAll())));
		}else{
			$sender->sendMessage("Usage: /minibosses create/spawn/delete/list");
		}
		return true;
	}
	
	public function spawnBoss(string $name = "Boss"){
		$data = $this->data->get($name);
		if(!$data || !$this->getServer()->getLevelByName($data["level"])) return false;
		$networkId = $data["network-id"];
		$pos = new Position($data["x"],$data["y"],$data["z"],$this->getServer()->getLevelByName($data["level"]));
		$health = $data["health"];
		$range = $data["health"];
		$attackDamage = $data["attackDamage"];
		$attackRate = $data["attackRate"];
		$speed = $data["speed"];
		$drops = $data["drops"];
		$respawnTime = $data["respawnTime"];
		$skin = ($networkId === 63 ? $data["skin"] : "");
		$heldItem = ($networkId === 63 ? $data["heldItem"] : "");
		$nbt = new CompoundTag("", [
            "Pos" => new ListTag("Pos", [
                new DoubleTag("", $pos->x),
                new DoubleTag("", $pos->y),
                new DoubleTag("", $pos->z)
            ]),
            "Motion" => new ListTag("Motion", [
                new DoubleTag("", 0),
                new DoubleTag("", 0),
                new DoubleTag("", 0)
            ]),
            "Rotation" => new ListTag("Rotation", [
                new FloatTag("", 0),
                new FloatTag("", 0)
            ]),
			"spawnPos" => new ListTag("spawnPos", [
                new DoubleTag("", $pos->x),
                new DoubleTag("", $pos->y),
                new DoubleTag("", $pos->z)
            ]),
			"range" => new FloatTag("range",$range * $range),
			"attackDamage" => new FloatTag("attackDamage",$attackDamage),
			"networkId" => new IntTag("networkId",$networkId),
			"attackRate" => new IntTag("attackRate",$attackRate),
			"speed" => new FloatTag("speed",$speed),
			"drops" => new StringTag("drops",$drops),
			"respawnTime" => new IntTag("respawnTime",$respawnTime),
			"skin" => new StringTag("skin",$skin),
			"heldItem" => new StringTag("heldItem",$heldItem)
            ]);
		$ent = Entity::createEntity("Boss",$pos->level->getChunk($pos->x >> 4,$pos->z >> 4,true),$nbt);
		$ent->setMaxHealth($health);
		$ent->setHealth($health);
		$ent->setNameTag($name);
		$ent->spawnToAll();
		return true;
	}
	
	public function respawn($name,$time){
		if($this->data->get($name)) $this->getServer()->getScheduler()->scheduleDelayedTask(new RespawnTask($this,$name), $time);
	}
}
class RespawnTask extends PluginTask{
	
	public function __construct(Main $plugin,$name){
		parent::__construct($plugin);
		$this->plugin = $plugin;
		$this->name = $name;
	}
	
	public function onRun($currentTick){
		$this->plugin->spawnBoss($this->name);
	}
}