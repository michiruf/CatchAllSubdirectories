<?php

/** @noinspection MultipleExpectChainableInspection */

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestBootstrap\TestDeployServer;

function sshCommand(string $password, string $command): string
{
    return "sshpass -p $password ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null application@localhost -p 8022 '$command'";
}

function updateDotEnv(string $password, array $env): void
{
    foreach ($env as $envName => $envValue) {
        $command = sshCommand($password, "sed -i \"s|\(# \)\?$envName=.*|$envName=$envValue|g\" /app/shared/.env");
        $updateEnv = Process::run($command);
        expect($updateEnv)->exitCode()->toBe(0, error($command, $updateEnv));
    }
}

function error(string $command, string|ProcessResult $output): string
{
    if ($output instanceof ProcessResult) {
        $output = $output->output();
    }
    return "Error running:\n$command\nWith output:\n$output}";
}

beforeEach(function () {
    $this->password = 'test';

    // Assert that sshpass is installed
    expect(Process::command('which sshpass')->run())
        ->exitCode()->toBe(0, 'sshpass must be installed on the test system');

    // First may clear the state, so we always start fresh, then start
    // Service name from _deploy/docker-compose.yml
    $this->deployServer = (new TestDeployServer('app', 300, $this->password))
        ->clearPersistence()
        ->start()
        ->awaitStart();
    expect($this->deployServer->log())->toContain(TestDeployServer::$startupMessage);
});

afterEach(function () {
    //    $this->deployServer
    //        ->stop()
    //        ->clearPersistence();
    //    expect($this->deployServer->log())->toBe('');
});

it('can deploy the application to the provided docker container in directory _deploy', function () {
    // We want to test with the latest git tag instead of the main branch
    // Unfortunately, this means that the current changes cannot be tested but must be committed and pushed first
    $gitHash = Process::path(base_path())
        ->command('git rev-parse HEAD')
        ->run()
        ->output();

    // -----------------------------------------------------------------
    // Perform the setup steps, that only need to be done once
    // -----------------------------------------------------------------
    $initialDeployCommand = "sshpass -p $this->password vendor/bin/dep deploy test --revision=$gitHash";
    $initialDeploy = Process::path(base_path())
        ->command($initialDeployCommand)
        ->timeout(300)
        ->run();
    expect($initialDeploy)
        ->exitCode()->toBe(1, error($initialDeployCommand, $initialDeploy))
        ->and($initialDeploy->output())
        ->toContain('.env file is empty')
        ->toContain('successfully deployed')
        ->toContain('Connection refused');

    $createDotEnvCommand = sshCommand($this->password, 'cp /app/current/.env.example /app/shared/.env');
    $createDotEnv = Process::run($createDotEnvCommand);
    expect($createDotEnv)
        ->exitCode()->toBe(0, error($createDotEnvCommand, $createDotEnv));

    // TODO Think about propagating docker env to user application inside the container
    updateDotEnv($this->password, [
        'APP_ENV' => 'production',
        'APP_DEBUG' => false,
        'REDIS_HOST' => 'redis',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'mysql',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'test',
        'DB_USERNAME' => 'test',
        'DB_PASSWORD' => 'test',
    ]);

    $setupAppKeyCommand = "sshpass -p $this->password vendor/bin/dep artisan:key:generate test";
    $setupAppKey = Process::path(base_path())
        ->command($setupAppKeyCommand)
        ->run();
    expect($setupAppKey)
        ->exitCode()->toBe(0, error($setupAppKeyCommand, $setupAppKey));

    // -----------------------------------------------------------------
    // Perform the deployment once more, when everything is set up
    // -----------------------------------------------------------------
    $deployCommand = "sshpass -p $this->password vendor/bin/dep deploy test --revision=$gitHash";
    $deploy = Process::path(base_path())
        ->command($deployCommand)
        ->timeout(300)
        ->run();
    expect($deploy)
        ->exitCode()->toBe(0, error($deployCommand, $deploy))
        ->and($deploy->output())
        ->not->toContain('.env file is empty')
        ->toContain('successfully deployed');

    // Wait for horizon and workers to get started properly (needed for tests below)
    $this->deployServer->awaitMessage('Horizon started successfully');
    $this->deployServer->awaitMessage('Running scheduled tasks');

    // -----------------------------------------------------------------
    // Start the tests
    // -----------------------------------------------------------------
    expect(Http::get('http://localhost:8080'))
        ->status()
        ->toBeGreaterThanOrEqual(200)
        ->toBeLessThan(400);

    retry(
        60,
        function () {
            $healthResponse = Http::get('http://localhost:8080/health.json?fresh');
            expect($healthResponse)
                ->status()->toBe(200)
                ->and(collect($healthResponse->json()['checkResults'])
                    ->mapWithKeys(fn (array $data) => [$data['name'] => $data['status']]))
                ->get('Cache')->toBe('ok', 'Cache status failed')
                ->get('Database')->toBe('ok', 'Database status failed')
                ->get('DebugMode')->toBe('ok', 'DebugMode status failed')
                ->get('Environment')->toBe('ok', 'Environment status failed')
                ->get('OptimizedApp')->toBe('ok', 'OptimizedApp status failed')
                ->get('Horizon')->toBe('ok', 'Horizon status failed')
                ->get('Schedule')->toBe('ok', 'Schedule status failed')
                ->get('Queue')->toBe('ok', 'Queue status failed')
                ->get('Redis')->toBe('ok', 'Redis status failed');
        },
        1000,
        fn ($exception) => $exception instanceof ExpectationFailedException
    );
})->skipOnWindows();
