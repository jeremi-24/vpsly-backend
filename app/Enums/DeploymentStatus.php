<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case PENDING = 'pending';
    case PREPARING = 'preparing';
    case CLONING = 'cloning';
    case BUILDING = 'building';
    case DEPLOYING = 'deploying';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'En attente',
            self::PREPARING => 'Préparation',
            self::CLONING => 'Récupération du code',
            self::BUILDING => 'Construction (Build)',
            self::DEPLOYING => 'Déploiement',
            self::RUNNING => 'En ligne',
            self::SUCCESS => 'Terminé',
            self::FAILED => 'Échoué',
        };
    }
}
