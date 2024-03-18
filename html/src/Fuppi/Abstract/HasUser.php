<?php 

namespace Fuppi\Abstract;

use Fuppi\User;

trait HasUser
{
    protected string $userIdColumnName = 'user_id';
    protected User $user;


    public function __construct()
    {
    }

    public function getUser(): ?User
    {
        if ($this->user_id > 0) {
            if (!isset($this->user) || $this->user->user_id !== $this->user_id) {
                $this->user = User::getOne($this->user_id);
            }
            return $this->user;
        }
        return null;
    }

}