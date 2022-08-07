<?php

namespace diamondgold\MiniBosses\data;

use pocketmine\item\Item;

class DropsEntry
{
    public function __construct(private Item $item, private int $chance)
    {
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function getChance(): int
    {
        return $this->chance;
    }
}
