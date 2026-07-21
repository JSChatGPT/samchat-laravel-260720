<?php

declare(strict_types=1);

namespace Kreait\Laravel\Firebase\Facades;

use Illuminate\Support\Facades\Facade;
use Kreait\Firebase\Contract\AppCheck;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Contract\Firestore;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Contract\RemoteConfig;
use Kreait\Firebase\Contract\Storage;
use Kreait\Laravel\Firebase\FirebaseProject;
use Kreait\Laravel\Firebase\FirebaseProjectManager;

/**
 * @method static FirebaseProject project(string $name = null)
 * @method static string getDefaultProject()
 * @method static void setDefaultProject(string $name)
 * @method static AppCheck appCheck()
 * @method static Auth auth()
 * @method static Database database()
 * @method static Firestore firestore()
 * @method static Messaging messaging()
 * @method static RemoteConfig remoteConfig()
 * @method static Storage storage()
 *
 * @see FirebaseProjectManager
 * @see FirebaseProject
 */
final class Firebase extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'firebase.manager';
    }
}
