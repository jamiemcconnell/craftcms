<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\app\events;

/**
 * RegisterUserPermissionsEvent class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class RegisterUserPermissionsEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var array List of registered user permissions.
     */
    public $permissions = [];
}