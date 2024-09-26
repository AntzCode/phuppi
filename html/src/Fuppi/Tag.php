<?php

namespace Fuppi;

use \Fuppi\Abstract\Model;
use \PDO;

class Tag extends Model
{

    protected string $_tablename = 'fuppi_tags';
    protected string $_primaryKeyColumnName = 'tag_id';

    protected $data = [
        'tag_id' => 0,
        'slug' => '',
        'tagname' => '',
        'notes' => ''
    ];

    public static function sanitizeTagname(string $tagname): string
    {
        return trim($tagname);
    }

    public static function getOne(int $id) : ?Tag
    {
        return parent::getOne($id);
    }

    public static function getOneByTagName(string $tagname) : ?Tag
    {
        $db = \Fuppi\App::getInstance()->getDb();
        $instance = new self();
        $statement = $db->getPdo()->prepare('SELECT `' . implode('`, `', array_keys($instance->getData())) . '` FROM `' . $instance->_tablename . '` WHERE `tagname` = :tagname');
        if($statement->execute(['tagname' => $tagname])){
            $data = $statement->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                $instance->setData($instance->fromDb($data));
                return $instance;
            }
        }
        return null;
    }

    /**
     * slugify && generate a unique slug that doesn't exist already
     */
    public static function generateSlug($tagname){
        $db = \Fuppi\App::getInstance()->getDb();
        $lowercaseTagname = strtolower(trim($tagname));
        $slug = preg_replace('/[^a-z0-9\-_\.]/', '', $lowercaseTagname);
        $slug = str_replace(' ', '_', $slug);
        
        $statement = $db->getPdo()->prepare("SELECT COUNT(*) AS `tcount` FROM `fuppi_tags` WHERE `slug` = :slug");
        
        $iterations = 0;
        $searchSlug = $slug;

        while($statement->execute(['slug' => $searchSlug]) && $statement->fetch()['tcount'] > 0 && $iterations < 500){
            $iterations++;
            $searchSlug = $searchSlug . '-' . $iterations;
        }

        return ($iterations === 500) ? md5(rand(0, 9999999).rand(0, 9999999)) : $slug;
        
    }

    public function isValidSlug(string $slug)
    {
        $db = \Fuppi\App::getInstance()->getDb();
        $statement = $db->getPdo()->prepare("SELECT COUNT(*) AS `tcount` FROM `fuppi_tags` WHERE `slug` = :slug");
        if($statement->execute(['slug' => $slug])){
            if($statement->fetch()['tcount'] > 0){
                return true;
            }
        }
        return false;
    }

}
