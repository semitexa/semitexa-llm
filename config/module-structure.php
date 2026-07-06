<?php

declare(strict_types=1);

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\LocalModuleStructureExtension;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;

if (!class_exists(LocalModuleStructureExtension::class) || !class_exists(ModuleStructureRule::class)) {
    return null;
}

return new LocalModuleStructureExtension(
    package: 'llm',
    topLevelFiles: [
        // Backward-compatibility shim: class_alias() forwards for the legacy
        // Semitexa\Llm\Policy\* enum namespace, loaded via composer.json
        // autoload.files (a class per file can't do this — aliasing is a
        // side effect, not a declaration). The only permitted src/ root file.
        'compat.php',
    ],
    reason: 'semitexa-llm ships a composer files-autoloaded class_alias shim (compat.php) preserving the legacy Semitexa\\Llm\\Policy\\* namespace.',
);
