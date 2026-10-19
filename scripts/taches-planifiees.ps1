#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Enregistre les tâches planifiées Windows de l'API AJSO Handball.

.DESCRIPTION
    Crée (ou met à jour) dans le Planificateur de tâches, dossier « \AJSO Handball\ » :
      - Import FFHB              : app:ffhb:import, toutes les 2 heures de 8 h à 22 h
      - Relecture feuilles FFHB  : app:ffhb:import --relire-feuilles, le lundi à 4 h
      - Sauvegarde               : app:sauvegarde, chaque nuit à 3 h
      - Purge messages           : app:messages:purger (RGPD), toutes les 4 semaines, le dimanche à 5 h
    Les tâches tournent avec le compte indiqué, même sans session ouverte (aucun mot de passe enregistré).
    Le dossier du projet est déduit de l'emplacement du script ; php.exe est cherché dans le PATH.
    Les journaux sont écrits par l'application dans var\log\.

.PARAMETER Php
    Chemin de php.exe (par défaut : celui trouvé dans le PATH).

.PARAMETER Utilisateur
    Compte Windows qui exécute les tâches (par défaut : l'utilisateur courant).

.PARAMETER Supprimer
    Retire les tâches au lieu de les créer.

.EXAMPLE
    # PowerShell lancé en administrateur, depuis le dossier du projet :
    powershell -ExecutionPolicy Bypass -File .\scripts\taches-planifiees.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\taches-planifiees.ps1 -Php "C:\php\php.exe"

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\taches-planifiees.ps1 -Supprimer
#>
param(
    [string]$Php,
    [string]$Utilisateur = "$env:USERDOMAIN\$env:USERNAME",
    [switch]$Supprimer
)

$ErrorActionPreference = 'Stop'
$Dossier = '\AJSO Handball\'
$Projet = Split-Path -Parent $PSScriptRoot
$Console = Join-Path $Projet 'bin\console'

$Taches = @(
    @{ Nom = 'Import FFHB'; Commande = 'app:ffhb:import'; Description = 'Matchs, scores, classements et feuilles de match depuis ffhandball.fr' },
    @{ Nom = 'Relecture feuilles FFHB'; Commande = 'app:ffhb:import --relire-feuilles'; Description = 'Relit les feuilles de match déjà lues (corrections de la fédération)' },
    @{ Nom = 'Sauvegarde'; Commande = 'app:sauvegarde'; Description = 'Base PostgreSQL et fichiers envoyés dans var\sauvegardes (14 dernières gardées)' },
    @{ Nom = 'Purge messages'; Commande = 'app:messages:purger'; Description = 'RGPD : messages de contact et préinscriptions de plus de 12 mois' }
)

if ($Supprimer) {
    foreach ($t in $Taches) {
        if (Get-ScheduledTask -TaskPath $Dossier -TaskName $t.Nom -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskPath $Dossier -TaskName $t.Nom -Confirm:$false
            Write-Host "Tâche retirée : $($t.Nom)"
        }
    }
    return
}

if (-not (Test-Path $Console)) {
    throw "bin\console introuvable dans $Projet : le script doit rester dans le dossier scripts\ du projet."
}
if (-not $Php) {
    $trouve = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($trouve) { $Php = $trouve.Source }
}
if (-not $Php -or -not (Test-Path $Php)) {
    throw 'php.exe introuvable : ajoutez PHP au PATH ou indiquez -Php "C:\chemin\vers\php.exe".'
}
Write-Host "Projet : $Projet"
Write-Host "PHP    : $Php"
Write-Host "Compte : $Utilisateur"

# Import toutes les 2 heures entre 8 h et 22 h (déclencheur quotidien répété pendant 15 h)
$import = New-ScheduledTaskTrigger -Daily -At '08:00'
$import.Repetition = (New-ScheduledTaskTrigger -Once -At '08:00' -RepetitionInterval (New-TimeSpan -Hours 2) -RepetitionDuration (New-TimeSpan -Hours 15)).Repetition

$Declencheurs = @{
    'Import FFHB'             = $import
    'Relecture feuilles FFHB' = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday -At '04:00'
    'Sauvegarde'              = New-ScheduledTaskTrigger -Daily -At '03:00'
    # Pas de déclencheur « mensuel » dans New-ScheduledTaskTrigger : toutes les 4 semaines
    'Purge messages'          = New-ScheduledTaskTrigger -Weekly -WeeksInterval 4 -DaysOfWeek Sunday -At '05:00'
}

# S4U : exécution sans session ouverte, sans mot de passe enregistré
$Principal = New-ScheduledTaskPrincipal -UserId $Utilisateur -LogonType S4U -RunLevel Limited
$Reglages = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)

foreach ($t in $Taches) {
    $action = New-ScheduledTaskAction -Execute $Php -Argument "`"$Console`" $($t.Commande) --no-interaction" -WorkingDirectory $Projet
    Register-ScheduledTask -TaskPath $Dossier -TaskName $t.Nom -Description $t.Description -Action $action `
        -Trigger $Declencheurs[$t.Nom] -Principal $Principal -Settings $Reglages -Force | Out-Null
    Write-Host "Tâche enregistrée : $($t.Nom)  ->  php bin\console $($t.Commande)"
}

# Ancienne tâche unique décrite dans une version précédente du README
if (Get-ScheduledTask -TaskPath '\' -TaskName 'AJSO import FFHB' -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskPath '\' -TaskName 'AJSO import FFHB' -Confirm:$false
    Write-Host 'Ancienne tâche « AJSO import FFHB » retirée (remplacée par « Import FFHB »).'
}

Write-Host ''
Get-ScheduledTask -TaskPath $Dossier | Get-ScheduledTaskInfo | Select-Object TaskName, NextRunTime | Format-Table -AutoSize
Write-Host "Essai immédiat : Start-ScheduledTask -TaskPath '$Dossier' -TaskName 'Sauvegarde'"
Write-Host "Résultat       : Get-ScheduledTask -TaskPath '$Dossier' | Get-ScheduledTaskInfo  (LastTaskResult 0 = succès)"
