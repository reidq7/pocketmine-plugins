<?php
/**
 ** MODULE:CommandSelector
 **
 ** Adds "@" prefixes.
 **
 ** See
 ** [Command Prefixes](http://minecraft.gamepedia.com/Commands#Target_selector_arguments)
 ** for an explanation on prefixes.
 **
 ** This only implements the following prefixes:
 **
 ** - @a - all players
 ** - @e - all entities (including players)
 ** - @r - random player/entity
 **
 ** The following selectors are implemented:
 **
 ** - c: (only for @r),count
 ** - m: game mode
 ** - type: entity type, use Player for player.
 ** - name: player's name`
 **
 **/

namespace aliuly\grabbag;

use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\command\CommandSender;

use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\server\RemoteServerCommandEvent;
use pocketmine\event\server\ServerCommandEvent;
use pocketmine\event\Timings;

use aliuly\grabbag\common\BasicCli;
use aliuly\grabbag\common\mc;

use aliuly\grabbag\selectors\All;
use aliuly\grabbag\selectors\AllEntity;
use aliuly\grabbag\selectors\Random;


class PlayerCommandPreprocessEvent_sub extends PlayerCommandPreprocessEvent{
}
class RemoteServerCommandEvent_sub extends RemoteServerCommandEvent{
}
class ServerCommandEvent_sub extends ServerCommandEvent{
}


class CmdSelMgr extends BasicCli implements Listener {
	protected $max;
	public function __construct($owner) {
		parent::__construct($owner);
		$this->max = 128;
		$this->owner->getServer()->getPluginManager()->registerEvents($this, $this->owner);
	}
	/**
	 * @priority HIGHEST
	 */
	public function onPlayerCmd(PlayerCommandPreprocessEvent $ev) {
		if ($ev instanceof PlayerCommandPreprocessEvent_sub) return;
		$line = $event->getMessage();
		if(substr($line, 0, 1) !== "/") return;
		if (!$ev->getPlayer()->hasPermission("gb.module.cmdsel")) return;
		$res = $this->processCmd(substr($line,1),$ev->getPlayer());
		if ($res === false) return;
		$ev->setCancelled();
		foreach($res as $c) {
			$this->owner->getServer()->getPluginManager()->callEvent($ne = new PlayerCommandPreprocessEvent_sub($ev->getSender(), "/".$c));
			if($ne->isCancelled()) continue;
			if (substr($ne->getMessage(),0,1) !== "/") continue;
			$this->owner->getServer()->dispatchCommand($ne->getSender(), substr($ne->getMessage(),1));
		}
	}
	/**
	 * @priority HIGHEST
	 */
	public function onRconCmd(RemoteServerCommandEvent $ev) {
		if ($ev instanceof RemoteServerCommandEvent_sub) return;
		$res = $this->processCmd($ev->getCommand(),$ev->getSender());
		if ($res === false) return;
		$ev->setCancelled();
		foreach($res as $c) {
			$this->owner->getServer()->getPluginManager()->callEvent($ne = new RemoteServerCommandEvent_sub($ev->getSender(), $c));
			if($ne->isCancelled()) continue;
			$this->owner->getServer()->dispatchCommand($ne->getSender(), $ne->getCommand());
		}
	}
	/**
	 * @priority HIGHEST
	 */
	public function onConsoleCmd(ServerCommandEvent $ev) {
		if ($ev instanceof ServerCommandEvent_sub) return;
		$res = $this->processCmd($ev->getCommand(),$ev->getSender());
		if ($res === false) return;
		$ev->setCancelled();
		foreach($res as $c) {
			$this->owner->getServer()->getPluginManager()->callEvent($ne = new ServerCommandEvent_sub($ev->getSender(), $c));
			if($ne->isCancelled()) continue;
			$this->owner->getServer()->dispatchCommand($ne->getSender(), $ne->getCommand());
		}
	}

	protected function processCmd($cmd,CommandSender $sender) {
		$tokens = preg_split('/\s+/',$cmd);

		$res = [ $tokens ];
		$ret = false;

		foreach ($tokens as $argc=>$argv){
			if (!$argc) continue; // Trivial case...
			if (substr($argv,0,1) !== "@" ) continue;

			$selector = substr($argv, 1);
			$sargs = [];
			if(($i = strpos($selector, "[")) !== false){
				foreach (explode(",",substr($selector,$i+1,-1)) as $kv) {
					$kvp = explode("=",$kv,2);
					if (count($kvp) != 2) {
						$sender->sendMessage(mc::_("Selector: invalid argument %1%",$kv));
						continue;
					}
					$sargs[$kvp[0]] = strtolower($kvp[1]);
				}
				$selector = substr($selector,0,$i);
				print_r($sargs);//##DEBUG
			}
			$results = $this->dispatchSelector($sender , $selector, $sargs);
			if (!is_array($results)) continue;
			$ret = true;
			$new = [];

			foreach ($res as $i) {
				foreach ($results as $j) {
					$tmpLine = $i;
					$tmpLine[$argc] = $j;
					$new[] = $tmpLine;
					if (count($new) > $this->max) break;
				}
				if (count($new) > $this->max) break;
			}
			$res = $new;
		}
		if (!$ret) return false;
		$new = [];
		foreach ($res as $i) {
			$new[] = implode(" ",$i);
		}
		return $new;
	}
	protected function dispatchSelector(CommandSender $sender,$selector,array $args) {
		switch ($selector) {
			case "a":
			  return All::select($this, $sender , $args);
			case "e":
				return AllEntity::select($this, $sender, $args);
			case "r":
			  return Random::select($this, $sender, $args);
		  //case "p":
		}
		return null;
	}
	public function getServer() {
		return $this->owner->getServer();
	}
	public function checkSelectors(array $args,CommandSender $sender, Entity $item) {
		foreach($args as $name => $value){
			switch($name){
				case "m":
					$mode = intval($value);
					if($mode === -1) break;
					// what is the point of adding this (in PC) when they can just safely leave this out?
					if(($item instanceof Player) && ($mode !== $item->getGamemode())) return false;
					break;
				case "name":
				  if ($value{0} === "!") {
						if(substr($value,1) === strtolower($item->getName())) return false;
					} else {
						if($value !== strtolower($item->getName())) return false;
					}
					break;
				case "type":
					if ($item instanceof Player) {
						$type = "player";
					} else {
						$type = strtolower($item->getSaveId());
					}
					if ($value{0} === "!") {
						if(substr($value,1) === $type) return false;
					} else {
						if($value !== $type) return false;
					}
					break;
					// x,y,z
					// r,rm
					// c
					// dx,dy,dz
					// rx,rxm
					// ry,rym
			}
		}
		return true;
	}
}
