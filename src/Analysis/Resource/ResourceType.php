<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Analysis\Resource;

enum ResourceType: string
{
    case Site = 'site';
    case Form = 'form';
    case Taxonomy = 'taxonomy';
    case Navigation = 'navigation';
    case Dictionary = 'dictionary';
    case Collection = 'collection';
    case Route = 'route';
    case AssetContainer = 'asset container';
    case OAuthProvider = 'OAuth provider';
    case Permission = 'permission';
    case SearchIndex = 'search index';
    case UserGroup = 'user group';
    case UserRole = 'user role';
}
