<?php

namespace diamondgold\MiniBosses;

use pocketmine\entity\Attribute;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\SpawnParticleEffectPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\player\Player;
use pocketmine\world\Explosion;

class BossProjectile extends Projectile
{
    protected string $networkId;
    protected float $explodeRadius;
    protected bool $explodeDestroyBlocks;
    protected bool $canBeAttacked;
    protected int $despawnAfter;
    protected bool $canBeDeflected;
    protected bool $followNearest;
    protected string $particle;
    /** @var EffectInstance[] */
    protected array $effects;

    public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null)
    {
        parent::__construct($location, $shootingEntity, $nbt);
        $this->setCanSaveWithChunk(false);
    }

    /**
     * @param mixed[] $data
     */
    public function setData(array $data): void
    {
        $this->networkId = $data["networkId"];
        $this->setBaseDamage($data["attackDamage"]);
        $this->explodeRadius = $data["explodeRadius"];
        $this->explodeDestroyBlocks = $data["explodeDestroyBlocks"];
        $this->setHealth($data["health"]);
        $this->canBeAttacked = $data["canBeAttacked"];
        $this->despawnAfter = $data["despawnAfter"];
        $this->gravity = $data["gravity"];
        $this->canBeDeflected = $data["canBeDeflected"];
        $this->followNearest = $data["followNearest"];
        $this->particle = $data["particle"];
        $this->effects = [];
        foreach ($data["effects"] as $effect) {
            $this->effects[] = new EffectInstance(
                StringToEffectParser::getInstance()->parse($effect["id"]),
                $effect["duration"],
                $effect["amplifier"],
                $effect["showParticles"] ?? true,
                $effect["ambient"] ?? false
            );
        }
    }

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(0.25, 0.25);
    }

    public static function getNetworkTypeId(): string
    {
        return "BossProjectile";
    }

    public function sendSpawnPacket(Player $player): void
    {
        if ($this->networkId !== EntityIds::PLAYER && !empty($this->networkId)) {
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
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->despawnAfter > 0 && $this->ticksLived > $this->despawnAfter) {
            $this->flagForDespawn();
        }
        if ($this->followNearest) {
            $player = null;
            $dist = PHP_INT_MAX;
            foreach ($this->getViewers() as $p) {
                if (!$p->isSpectator() && $p->isAlive() && ($d = $p->location->distanceSquared($this->location)) < $dist) {
                    $player = $p;
                    $dist = $d;
                }
            }
            if ($player !== null) {
                $this->setMotion($player->getEyePos()->subtractVector($this->location)->normalize()->multiply($this->motion->length()));
            }
        }
        if (!empty($this->particle)) {
            NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [
                SpawnParticleEffectPacket::create(DimensionIds::OVERWORLD, -1, $this->getPosition(), $this->particle, null)
            ]);
        }
        return parent::onUpdate($currentTick);
    }

    protected function onHit(ProjectileHitEvent $event): void
    {
        parent::onHit($event);
        if ($this->explodeRadius > 0) {
            $explosion = new Explosion($this->location, $this->explodeRadius, $this);
            if ($this->explodeDestroyBlocks) {
                $explosion->explodeA();
            }
            $explosion->explodeB();
        }
        $this->flagForDespawn();
    }

    protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult): void
    {
        parent::onHitEntity($entityHit, $hitResult);
        if ($entityHit instanceof Living) {
            foreach ($this->effects as $effect) {
                $entityHit->getEffects()->add(clone $effect);
            }
        }
    }

    public function canCollideWith(Entity $entity): bool
    {
        return !$entity instanceof Boss && parent::canCollideWith($entity);
    }

    public function attack(EntityDamageEvent $source): void
    {
        if ($this->canBeAttacked) {
            Entity::attack($source);
            if ($source instanceof EntityDamageByEntityEvent) {
                $attacker = $source->getDamager();
                if ($attacker && $this->canBeDeflected) {
                    $this->setMotion($attacker->getDirectionVector()->multiply($this->motion->length()));
                }
            }
        } else {
            parent::attack($source);
        }
    }

    protected function getInitialDragMultiplier(): float
    {
        return 0.01;
    }

    protected function getInitialGravity(): float
    {
        return 0; // unused, overwritten in setData
    }
}
