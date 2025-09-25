<?php

declare(strict_types=1);
/**
 * This file is part of the Xmsjdev.
 *
 * (c) Xmsjdev <dev@xiamenshiji.vip>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
 
namespace Xmsjdev\Ip2region;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
            ],
            'commands' => [
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
        ];
    }
}
