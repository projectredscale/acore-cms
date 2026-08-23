<?php

namespace ACore\Components\UnstuckMenu;

use ACore\Manager\ACoreServices;
use ACore\Components\UnstuckMenu\UnstuckView;
use InvalidArgumentException;

class UnstuckController
{
    private $view;

    const HS_SPELL = 8690;
    const HS_ITEM = 6948;
    const CD_DURATION = 30 * 60;

    public function __construct()
    {
        $this->view = new UnstuckView($this);
    }

    public function renderCharacters()
    {
        $chars = self::getCharactersByAcId();
        echo $this->view->getUnstuckmenuRender($chars);
    }

    public static function unstuck($charName)
    {
        $soap = ACoreServices::I()->getUnstuckSoap();
        $conn = ACoreServices::I()->getCharacterEm()->getConnection();

        $character = self::fetchCharacterByName($charName);
        if (!$character) {
            throw new InvalidArgumentException("Character not found");
        }

        $guid = $character['guid'];
        $now = time();

        // Check if hearthstone is still on cooldown
        $existing = $conn->fetchAssociative(
            "SELECT `time` FROM character_spell_cooldown WHERE guid = :guid AND spell = :spell",
            ['guid' => $guid, 'spell' => self::HS_SPELL]
        );

        if ($existing && $existing['time'] > $now) {
            return $charName . " unstuck still on cooldown!";
        }

        $soap->unstuckByName($charName);

        $newTime = $now + self::CD_DURATION;

        $conn->executeStatement(
            "INSERT INTO character_spell_cooldown (guid, spell, item, time)
             VALUES (:guid, :spell, :item, :time)
             ON DUPLICATE KEY UPDATE `time` = :time2",
            ['guid' => $guid, 'spell' => self::HS_SPELL, 'item' => self::HS_ITEM, 'time' => $newTime, 'time2' => $newTime]
        );

        return $charName . " unstucked!";
    }

    public static function getCharactersByAcId()
    {
        $accId = ACoreServices::I()->getAcoreAccountId();

        if (!isset($accId) || $accId === null || $accId === '' || trim($accId) === '' || !is_numeric($accId)) {
            throw new InvalidArgumentException("Invalid user account ID provided.");
        }

        $query = "SELECT
            c.`guid`, c.`name`, c.`order`, c.`race`, c.`class`, c.`level`, c.`gender`, csc.`time`
            FROM `characters` c
            LEFT JOIN `character_spell_cooldown` csc ON c.`guid` = csc.`guid` AND csc.`spell` = :hsSpell
            WHERE c.`deleteDate` IS NULL
            AND c.`online` = 0
            AND c.`account` = :accId
            ORDER BY COALESCE(c.`order`, c.`guid`)
        ";
        $conn = ACoreServices::I()->getCharacterEm()->getConnection();
        $queryResult = $conn->executeQuery($query, ['accId' => $accId, 'hsSpell' => self::HS_SPELL]);
        return $queryResult->fetchAllAssociative();
    }

    private static function fetchCharacterByName($charName)
    {
        $accId = ACoreServices::I()->getAcoreAccountId();
        $conn = ACoreServices::I()->getCharacterEm()->getConnection();

        return $conn->fetchAssociative(
            "SELECT guid, name, race, `order` FROM characters
             WHERE name = :name AND account = :accId AND deleteDate IS NULL AND online = 0",
            ['name' => $charName, 'accId' => $accId]
        );
    }
}
