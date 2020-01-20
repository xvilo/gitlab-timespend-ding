<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use AlecRabbit\Spinner\SnakeSpinner;
use ProgressBar\Manager as ProgressBarManager;

$dotEnv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotEnv->load();

$mainGroup = 390;
$allGroupInfos = [];
$allIssues = [];
$allNotes = [];

if (file_exists('groups.json')) {
    $allGroupInfos = json_decode(file_get_contents('groups.json'), true);
} else {
    echo '🔍  Getting all groups and subgroups  ';
    $spinner = new SnakeSpinner();
    $spinner->begin();

    getSubgroups(getenv('MAIN_GROUP_ID'), $spinner);
    $groupsJson = json_encode($allGroupInfos);
    file_put_contents('groups.json', $groupsJson);
    echo PHP_EOL;
}

$totals = getTotals($allGroupInfos);

echo "🔍️  Using " . $totals['groups'] . " groups and " . $totals['projects'] . " projects" . PHP_EOL;
echo '🔍  Selected groups: ';
foreach(array_reverse($allGroupInfos) as $group) {
    echo "\033[0;35m{$group['name']}";
    echo "\e[1;37m, ";
}
echo PHP_EOL;

if (file_exists('issues.json')) {
    $allIssues = json_decode(file_get_contents('issues.json'), true);
} else {
    echo '📦  Fetching issues of all ' . $totals['projects'] . ' projects...' . PHP_EOL;

    $progressBar = new ProgressBarManager(0, $totals['projects']);
    $i = 0;
    foreach($allGroupInfos as $group) {
        foreach ($group['projects'] as $project) {
            $progressBar->update($i);
            $projectId = $project['id'];
            $allProjectIssues = getUri("/projects/$projectId/issues");
            foreach ($allProjectIssues as $issue) {
                $allIssues[$issue['id']] = $issue;
            }

            $i++;
        }
    }

    file_put_contents('issues.json', json_encode($allIssues));
    echo PHP_EOL;
}

echo '📦  Using ' . count($allIssues) . ' issues...' . PHP_EOL;

echo '⚙️️  Processing issues...' . PHP_EOL;
$progressBar = new ProgressBarManager(0, count($allIssues));
$progressBar->setFormat('⚙️️  Progress: %current%/%max% %eta% %title%');
$i = 0;

if (file_exists('notes.json')) {
    $allNotes = json_decode(file_get_contents('notes.json'), true);
} else {
    foreach ($allIssues as $issue) {
        $progressBar->addReplacementRule('%title%', 70, function ($buffer, $registry) use ($issue) {
            return "\033[0;35m{$issue['title']}                                \e[1;37m";
        });
        $notes = getUri($issue['_links']['notes']);
        $allNotes[$issue['id']][] = $notes;

        $progressBar->update($i);
        $i++;
        file_put_contents('notes.json', json_encode($allNotes));
    }
}

$totalNotes = 0;
foreach ($allNotes as $note) {
    $totalNotes+= count($note);
}

echo PHP_EOL . "⚙️️  Got {$totalNotes} notes" . PHP_EOL;


function getUri(string $uri): ?array {
    $host = getenv('GITLAB_API_URL');
    $key = getenv('GITLAB_API_TOKEN');
    $uriReplaces = str_replace($host, '', $uri);
    $data = file_get_contents("$host$uriReplaces?private_token=$key");

    if (count($data) > 0) {
        return json_decode($data, true);
    }

    return null;
}

function getTotals(array $datas): array{
    $totalProjects = 0;
    $totalGroups = count($datas);
    foreach($datas as $group) {
        $totalProjects += count($group['projects']);
    }

    return [
        'groups' => $totalGroups,
        'projects' => $totalProjects
    ];
}

function getSubgroups($group, AlecRabbit\Spinner\SnakeSpinner $spinner) {
    $spinner->spin();
    $groupInfo = getUri("/groups/$group");
    $spinner->spin();
    $fullName = $groupInfo['full_name'];
    $projects = $groupInfo['projects'];
    $id = $groupInfo['id'];
    $subgroups = getUri("/groups/$group/subgroups");
    $spinner->spin();

    foreach ($subgroups as $subgroup) {
        getSubgroups($subgroup['id'], $spinner);
        $spinner->spin();
    }

    global $allGroupInfos;

    $allGroupInfos[$id] = [
        'name' => $fullName,
        'projects' => $projects
    ];
}

