<?php

namespace App\Traits;

trait EnvironmentVariableAnalyzer
{
    protected static function getProblematicBuildVariables(): array
    {
        return [
            'NODE_ENV' => [
                'problematic_values' => ['production', 'prod'],
                'affects' => 'Node.js/npm/yarn/bun/pnpm',
                'issue' => 'Skips devDependencies installation which are often required for building',
                'recommendation' => 'Uncheck "Available at Buildtime" or use "development" during build',
            ],
            'APP_ENV' => [
                'problematic_values' => ['production', 'prod'],
                'affects' => 'Laravel/Symfony',
                'issue' => 'May affect dependency installation and build optimizations',
                'recommendation' => 'Consider using "local" or "development" for build',
            ],
            'NPM_CONFIG_PRODUCTION' => [
                'problematic_values' => ['true', '1', 'yes'],
                'affects' => 'npm/pnpm',
                'issue' => 'Forces npm to skip devDependencies',
                'recommendation' => 'Remove from build-time variables or set to false',
            ],
        ];
    }

    public static function analyzeBuildVariable(string $key, string $value): ?array
    {
        $problematicVars = self::getProblematicBuildVariables();
        if (isset($problematicVars[$key])) {
            $config = $problematicVars[$key];
            return [
                'variable' => $key,
                'value' => $value,
                'affects' => $config['affects'],
                'issue' => $config['issue'],
                'recommendation' => $config['recommendation'],
            ];
        }
        return null;
    }

    public static function analyzeBuildVariables(array $variables): array
    {
        $warnings = [];
        foreach ($variables as $key => $value) {
            $warning = self::analyzeBuildVariable($key, (string)$value);
            if ($warning) {
                $warnings[] = $warning;
            }
        }
        return $warnings;
    }
}
