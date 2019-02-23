<?php

/*
 *                _   _
 *  ___  __   __ (_) | |   ___
 * / __| \ \ / / | | | |  / _ \
 * \__ \  \ / /  | | | | |  __/
 * |___/   \_/   |_| |_|  \___|
 *
 * SkyWars plugin for PocketMine-MP & forks
 *
 * @Author: svile
 * @Kik: _svile_
 * @Telegram_Group: https://telegram.me/svile
 * @E-mail: thesville@gmail.com
 * @Github: https://github.com/svilex/SkyWars-PocketMine
 *
 * Copyright (C) 2016 svile
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace svile\sw;

use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\level\sound\{ClickSound, EndermanTeleportSound, Sound};
use pocketmine\Player;
use pocketmine\tile\Chest;
use pocketmine\utils\{Config, TextFormat};

class SWarena {

    //Player states
    const PLAYER_NOT_FOUND = 0;
    const PLAYER_PLAYING = 1;
    const PLAYER_SPECTATING = 2;

    //Game states
    const STATE_COUNTDOWN = 0;
    const STATE_RUNNING = 1;
    const STATE_NOPVP = 2;

    /** @var PlayerSnapshot[] */
    private $playerSnapshots = [];//store player's inventory, health etc pre-match so they don't lose it once the match ends

    /** @var int */
    public $GAME_STATE = SWarena::STATE_COUNTDOWN;

    /** @var SWmain */
    private $plugin;

    /** @var string */
    private $SWname;

    /** @var int */
    private $slot;

    /** @var string */
    private $world;

    /** @var int */
    private $countdown = 60;//Seconds to wait before the game starts

    /** @var int */
    private $maxtime = 300;//Max seconds after the countdown, if go over this, the game will finish

    /** @var int */
    public $void = 0;//This is used to check "fake void" to avoid fall (stunck in air) bug

    /** @var array */
    private $spawns = [];//Players spawns

    /** @var int */
    private $time = 0;//Seconds from the last reload | GAME_STATE

    /** @var string[] */
    private $players = [];//[rawUUID] => int(player state)

    /** @var array[] */
    private $playerSpawns = [];

    /**
     * @param SWmain $plugin
     * @param string $SWname
     * @param int $slot
     * @param string $world
     * @param int $countdown
     * @param int $maxtime
     * @param int $void
     */
    public function __construct(SWmain $plugin, string $SWname = "sw", int $slot = 0, string $world = "world", int $countdown = 60, int $maxtime = 300, int $void = 0)
    {
        $this->plugin = $plugin;
        $this->SWname = $SWname;
        $this->slot = $slot;
        $this->world = $world;
        $this->countdown = $countdown;
        $this->maxtime = $maxtime;
        $this->void = $void;

        if (!$this->reload($error)) {
            $logger = $this->plugin->getLogger();
            $logger->error("An error occured while reloading the arena: " . TextFormat::YELLOW . $this->SWname);
            $logger->error($error);
            $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
        }
    }

    final public function getName() : string
    {
        return $this->SWname;
    }

    /**
     * @return bool
     */
    private function reload(&$error = null) : bool
    {
        //Map reset
        if (!is_file($file = $this->plugin->getDataFolder() . "arenas/" . $this->SWname . "/" . $this->world . ".tar") && !is_file($file = $this->plugin->getDataFolder() . "arenas/" . $this->SWname . "/" . $this->world . ".tar.gz")) {
            $error = "Cannot find world backup file $file";
            return false;
        }

        $server = $this->plugin->getServer();

        if ($server->isLevelLoaded($this->world)) {
            $server->unloadLevel($server->getLevelByName($this->world));
        }

        if ($this->plugin->configs["world.reset.from.tar"]) {
            $tar = new \PharData($file);
            $tar->extractTo($server->getDataPath() . "worlds/" . $this->world, null, true);
        }

        $server->loadLevel($this->world);
        $server->getLevelByName($this->world)->setAutoSave(false);

        $config = new Config($this->plugin->getDataFolder() . "arenas/" . $this->SWname . "/settings.yml", Config::YAML, [//TODO: put descriptions
            "name" => $this->SWname,
            "slot" => $this->slot,
            "world" => $this->world,
            "countdown" => $this->countdown,
            "maxGameTime" => $this->maxtime,
            "void_Y" => $this->void,
            "spawns" => []
        ]);

        $this->SWname = $config->get("name");
        $this->slot = (int) $config->get("slot");
        $this->world = $config->get("world");
        $this->countdown = (int) $config->get("countdown");
        $this->maxtime = (int) $config->get("maxGameTime");
        $this->spawns = $config->get("spawns");
        $this->void = (int) $config->get("void_Y");

        $this->players = [];
        $this->time = 0;
        $this->GAME_STATE = SWarena::STATE_COUNTDOWN;

        //Reset Sign
        $this->plugin->refreshSigns($this->SWname, 0, $this->slot);
        return true;
    }

    public function getState() : string
    {
        if ($this->GAME_STATE !== SWarena::STATE_COUNTDOWN || count(array_keys($this->players, SWarena::PLAYER_PLAYING, true)) >= $this->slot) {
            return TextFormat::RED . TextFormat::BOLD . "Running";
        }

        return TextFormat::WHITE . "Tap to join";
    }

    public function getSlot(bool $players = false) : int
    {
        return $players ? count($this->players) : $this->slot;
    }

    public function getWorld() : string
    {
        return $this->world;
    }

    /**
     * @param Player $player
     * @return int
     */
    public function inArena(Player $player) : int
    {
        return $this->players[$player->getRawUniqueId()] ?? SWarena::PLAYER_NOT_FOUND;
    }

    public function setPlayerState(Player $player, ?int $state) : void
    {
        if ($state === null || $state === SWarena::PLAYER_NOT_FOUND) {
            unset($this->players[$player->getRawUniqueId()]);
            return;
        }

        $this->players[$player->getRawUniqueId()] = $state;
    }

    /**
     * @param Player $player
     * @param int $slot
     * @return bool
     */
    public function setSpawn(Player $player, int $slot = 1) : bool
    {
        if ($slot > $this->slot) {
            $player->sendMessage(TextFormat::RED . "This arena have only got " . TextFormat::WHITE . $this->slot . TextFormat::RED . " slots");
            return false;
        }

        $config = new Config($this->plugin->getDataFolder() . "arenas/" . $this->SWname . "/settings.yml", Config::YAML);

        if (empty($config->get("spawns", []))) {
            $config->set("spawns", array_fill(1, $this->slot, [
                "x" => "n.a",
                "y" => "n.a",
                "z" => "n.a",
                "yaw" => "n.a",
                "pitch" => "n.a"
            ]));
        }
        $s = $config->get("spawns");
        $s[$slot] = [
            "x" => floor($player->x),
            "y" => floor($player->y),
            "z" => floor($player->z),
            "yaw" => $player->yaw,
            "pitch" => $player->pitch
        ];

        $config->set("spawns", $s);
        $this->spawns = $s;

        if (!$config->save() || count($this->spawns) !== $this->slot) {
            $player->sendMessage(TextFormat::RED . "An error occured setting the spawn, please contact the developer.");
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function checkSpawns() : bool
    {
        if (empty($this->spawns)) {
            return false;
        }

        foreach ($this->spawns as $key => $val) {
            if (!is_array($val) || count($val) !== 5 || $this->slot !== count($this->spawns) || in_array("n.a", $val, true)) {
                return false;
            }
        }
        return true;
    }

    private function refillChests() : void
    {
        $contents = $this->plugin->getChestContents();

        foreach ($this->plugin->getServer()->getLevelByName($this->world)->getTiles() as $tile) {
            if ($tile instanceof Chest) {

                $inventory = $tile->getInventory();
                $inventory->clearAll(false);

                if (empty($contents)) {
                    $contents = $this->plugin->getChestContents();
                }

                foreach (array_shift($contents) as $key => $val) {
                    $inventory->setItem($key, Item::get($val[0], 0, $val[1]), false);
                }

                $inventory->sendContents($inventory->getViewers());
            }
        }
    }

    public function tick() : void
    {
        $config = $this->plugin->configs;

        switch ($this->GAME_STATE) {
            case SWarena::STATE_COUNTDOWN:
                $player_cnt = count($this->players);

                if ($player_cnt < $config["needed.players.to.run.countdown"]) {
                    return;
                }

                if (($config["start.when.full"] && $this->slot <= $player_cnt) || $this->time >= $this->countdown) {
                    $this->start();
                    return;
                }

                if ($this->time % 30 === 0) {
                    $this->sendMessage(str_replace("{N}", date("i:s", ($this->countdown - $this->time)), $this->plugin->lang["chat.countdown"]));
                }

                $this->sendPopup(str_replace("{N}", date("i:s", ($this->countdown - $this->time)), $this->plugin->lang["popup.countdown"]));
                $this->sendSound(ClickSound::class);
                break;
            case SWarena::STATE_RUNNING:
                $player_cnt = count(array_keys($this->players, SWarena::PLAYER_PLAYING, true));
                if ($player_cnt < 2 || $this->time >= $this->maxtime) {
                    $this->stop();
                    return;
                }

                if ($config["chest.refill"] && ($this->time % $config["chest.refill.rate"] === 0)) {
                    $this->sendMessage($this->plugin->lang["game.chest.refill"]);
                }
                break;
            case SWarena::STATE_NOPVP:
                if ($this->time <= $config["no.pvp.countdown"]) {
                    $this->sendPopup(str_replace("{COUNT}", $config["no.pvp.countdown"] - $this->time + 1, $this->plugin->lang["no.pvp.countdown"]));
                } else {
                    $this->GAME_STATE = SWarena::STATE_RUNNING;
                }
                break;
        }

        ++$this->time;
    }

    public function join(Player $player, bool $sendErrorMessage = true) : bool
    {
        if ($this->GAME_STATE !== SWarena::STATE_COUNTDOWN) {
            if ($sendErrorMessage) {
                $player->sendMessage($this->plugin->lang["sign.game.running"]);
            }
            return false;
        }

        if (count($this->players) >= $this->slot || empty($this->spawns)) {
            if ($sendErrorMessage) {
                $player->sendMessage($this->plugin->lang["sign.game.full"]);
            }
            return false;
        }

        //Sound
        $player->getLevel()->addSound(new EndermanTeleportSound($player), [$player]);

        //Removes player things
        $player->setGamemode(Player::SURVIVAL);
        $this->playerSnapshots[$player->getId()] = new PlayerSnapshot($player, $this->plugin->configs["clear.inventory.on.arena.join"], $this->plugin->configs["clear.effects.on.arena.join"]);
        $player->setMaxHealth($this->plugin->configs["join.max.health"]);

        if ($player->getAttributeMap() != null) {//just to be really sure
            if (($health = $this->plugin->configs["join.health"]) > $player->getMaxHealth() || $health < 1) {
                $health = $player->getMaxHealth();
            }
            $player->setHealth($health);
            $player->setFood(20);
        }

        $server = $this->plugin->getServer();
        $server->loadLevel($this->world);
        $level = $server->getLevelByName($this->world);

        $tmp = array_shift($this->spawns);
        $player->teleport(new Position($tmp["x"] + 0.5, $tmp["y"], $tmp["z"] + 0.5, $level), $tmp["yaw"], $tmp["pitch"]);
        $this->playerSpawns[$player->getRawUniqueId()] = $tmp;

        $this->setPlayerState($player, SWarena::PLAYER_PLAYING);
        $this->plugin->setPlayerArena($player, $this->getName());
        $player->setImmobile(true);

        $this->sendMessage(str_replace("{COUNT}", "[" . $this->getSlot(true) . "/" . $this->slot . "]", str_replace("{PLAYER}", $player->getName(), $this->plugin->lang["game.join"])));
        $this->plugin->refreshSigns($this->SWname, $this->getSlot(true), $this->slot, $this->getState());
        return true;
    }

    public function getPlayers(?int $player_state = null) : array
    {
        return array_intersect_key($this->plugin->getServer()->getOnlinePlayers(), $player_state === null ? $this->players : array_intersect($this->players, [$player_state]));
    }

    public function sendMessage(string $message) : void
    {
        $this->plugin->getServer()->broadcastMessage($message, $this->getPlayers());
    }

    public function sendPopup(string $message) : void
    {
        $this->plugin->getServer()->broadcastPopup($message, $this->getPlayers());
    }

    public function sendSound(string $sound_class) : void
    {
        if (!is_subclass_of($sound_class, Sound::class, true)) {
            throw new \InvalidArgumentException($sound_class . " must be an instance of " . Sound::class);
        }

        foreach ($this->getPlayers() as $player) {
            $player->getLevel()->addSound(new $sound_class($player), [$player]);
        }
    }

    /**
     * @param Player $player
     * @param bool $left
     * @param bool $spectate
     * @return bool
     */
    private function quit(Player $player, bool $left = false, bool $spectate = false) : bool
    {
        $current_state = $this->inArena($player);
        if ($current_state === SWarena::PLAYER_NOT_FOUND) {
            return false;
        }

        $this->setPlayerState($player, null);

        if ($this->GAME_STATE === SWarena::STATE_COUNTDOWN) {
            $player->setImmobile(false);
            $this->spawns[] = $this->playerSpawns[$uuid = $player->getRawUniqueId()];
            unset($this->playerSpawns[$uuid]);
        }

        if ($current_state === SWarena::PLAYER_SPECTATING) {
            foreach ($this->getPlayers() as $pl) {
                $pl->showPlayer($player);
            }

            $this->setPlayerState($player, null);
            return true;
        }

        $this->plugin->setPlayerArena($player, null);
        $this->plugin->refreshSigns($this->SWname, $this->getSlot(true), $this->slot, $this->getState());

        if ($left) {
            $this->sendMessage(str_replace("{COUNT}", "[" . $this->getSlot(true) . "/" . $this->slot . "]", str_replace("{PLAYER}", $player->getDisplayName(), $this->plugin->lang["game.left"])));
        }

        if ($spectate && $current_state !== SWarena::PLAYER_SPECTATING) {
            $this->setPlayerState($player, SWarena::PLAYER_SPECTATING);
            foreach ($this->getPlayers(SWarena::PLAYER_SPECTATING) as $pl) {
                $pl->showPlayer($player);
            }
        }
        return true;
    }

    /**
     * @param Player $p
     * @param bool $left
     * @param bool $spectate
     * @return bool
     */
    public function closePlayer(Player $player, bool $left = false, bool $spectate = false) : bool
    {
        if ($this->quit($player, $left, $spectate)) {
            $player->setGamemode($player->getServer()->getDefaultGamemode());
            if (!$spectate) {
                //TODO: Invisibility issues for death players
                $player->teleport($player->getServer()->getDefaultLevel()->getSpawnLocation());
                $playerSnapshot = $this->playerSnapshots[$player->getId()];
                unset($this->playerSnapshots[$player->getId()]);
                $playerSnapshot->injectInto($player);
            } elseif ($this->GAME_STATE !== SWarena::STATE_COUNTDOWN && 1 < count(array_keys($this->players, SWarena::PLAYER_PLAYING, true))) {
                $player->setGamemode(Player::SPECTATOR);
                foreach ($this->getPlayers() as $pl) {
                    $pl->hidePlayer($player);
                }

                $idmeta = explode(":", $this->plugin->configs["spectator.quit.item"]);
                $inventory = $player->getInventory();

                $inventory->setHeldItemIndex(0);
                $inventory->setItemInHand(Item::get((int)$idmeta[0], (int)$idmeta[1], 1));
                $inventory->setHeldItemIndex(1);

                $player->sendMessage($this->plugin->lang["death.spectator"]);
            }
            return true;
        }
        return false;
    }

    private function start() : void
    {
        if ($this->plugin->configs["chest.refill"]) {
            $this->refillChests();
        }

        foreach ($this->getPlayers() as $player) {
            $player->setMaxHealth($this->plugin->configs["join.max.health"]);
            $player->setMaxHealth($player->getMaxHealth());
            if ($player->getAttributeMap() !== null) {//just to be really sure
                if (($health = $this->plugin->configs["join.health"]) > $player->getMaxHealth() || $health < 1) {
                    $health = $player->getMaxHealth();
                }
                $player->setHealth($health);
                $player->setFood(20);
            }

            $player->sendMessage($this->plugin->lang["game.start"]);

            $level = $player->getLevel();
            $pos = $player->floor();

            for ($i = 1; $i <= 2; ++$i) {
                if ($level->getBlockIdAt($pos->x, $pos->y - $i, $pos->z) === Block::GLASS) {
                    $level->setBlock($pos->subtract(0, $i, 0), Block::get(Block::AIR), false);
                }
            }

            $player->setImmobile(false);
        }

        $this->time = 0;
        $this->GAME_STATE = SWarena::STATE_NOPVP;
        $this->plugin->refreshSigns($this->SWname, $this->getSlot(true), $this->slot, $this->getState());
    }

    public function stop(bool $force = false) : bool
    {
        $server = $this->plugin->getServer();
        $server->loadLevel($this->world);

        foreach ($this->getPlayers() as $player) {
            $is_winner = !$force && $this->inArena($player) === SWarena::PLAYER_PLAYING;
            $this->closePlayer($player);

            if ($is_winner) {
                //Broadcast winner
                $server->broadcastMessage(str_replace(["{SWNAME}", "{PLAYER}"], [$this->SWname, $player->getName()], $this->plugin->lang["server.broadcast.winner"]), $server->getDefaultLevel()->getPlayers());

                //Economy reward
                if ($this->plugin->configs["reward.winning.players"] && is_numeric($this->plugin->configs["reward.value"]) && is_int(($this->plugin->configs["reward.value"] + 0)) && $this->plugin->economy instanceof \svile\sw\utils\SWeconomy && $this->plugin->economy->getApiVersion() != 0) {
                    $this->plugin->economy->addMoney($player, (int)$this->plugin->configs["reward.value"]);
                    $player->sendMessage(str_replace(["{MONEY}", "{VALUE}"], [$this->plugin->economy->getMoney($player), $this->plugin->configs["reward.value"]], $this->plugin->lang["winner.reward.msg"]));
                }

                //Reward command
                $command = trim($this->plugin->configs["reward.command"]);
                if (strlen($command) > 1 && $command{0} === "/") {
                    $this->plugin->getServer()->dispatchCommand(new \pocketmine\command\ConsoleCommandSender(), str_replace("{PLAYER}", $p->getName(), substr($command, 1)));
                }
            }
        }

        $this->reload();
        return true;
    }
}
