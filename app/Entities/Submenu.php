<?php

namespace App\Entities;

use Illuminate\Database\Eloquent\Model;
use Prettus\Repository\Contracts\Transformable;
use Prettus\Repository\Traits\TransformableTrait;

/**
 * Class Submenu.
 *
 * @package namespace App\Entities;
 */
class Submenu extends Model implements Transformable
{
    const SUPER_USER_MENU_ID = 0;

    use TransformableTrait;

    /**
     * @var string
     */
    protected $table = 'portal.menu_submenu';

    /**
     * @var string
     */
    protected $primaryKey = 'cod_menu_submenu';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'ref_cod_menu_menu', 'cod_menu_menu');
    }

    public function typeUsers()
    {
        return $this->belongsToMany(
            UserType::class,
            'pmieducar.menu_tipo_usuario',
            'ref_cod_menu_submenu',
            'ref_cod_tipo_usuario'
        );
    }
}