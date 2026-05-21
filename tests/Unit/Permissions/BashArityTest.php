<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Permissions;

use PHPUnit\Framework\TestCase;
use SuperAgent\Permissions\BashArity;

class BashArityTest extends TestCase
{
    public function test_single_token_coreutil(): void
    {
        $this->assertSame(['touch'], BashArity::prefix(['touch', 'foo.txt']));
        $this->assertSame(['ls'], BashArity::prefix(['ls', '-la']));
        $this->assertSame(['rm'], BashArity::prefix(['rm', '-rf', 'dir']));
    }

    public function test_two_token_command(): void
    {
        $this->assertSame(['git', 'checkout'], BashArity::prefix(['git', 'checkout', 'main']));
        $this->assertSame(['docker', 'run'], BashArity::prefix(['docker', 'run', 'nginx']));
        $this->assertSame(['pnpm', 'install'], BashArity::prefix(['pnpm', 'install']));
    }

    public function test_three_token_subcommand_wins_over_parent(): void
    {
        // `docker compose` is 3-arity → keep "docker compose up" → ['docker','compose','up']
        $this->assertSame(['docker', 'compose', 'up'], BashArity::prefix(['docker', 'compose', 'up', '-d']));
        // `pnpm run` is 3-arity → ['pnpm','run','dev']
        $this->assertSame(['pnpm', 'run', 'dev'], BashArity::prefix(['pnpm', 'run', 'dev']));
        // `terraform workspace` is 3-arity
        $this->assertSame(['terraform', 'workspace', 'select'], BashArity::prefix(['terraform', 'workspace', 'select', 'prod']));
        // `vault kv` is 3-arity
        $this->assertSame(['vault', 'kv', 'get'], BashArity::prefix(['vault', 'kv', 'get', 'secret/api']));
    }

    public function test_unknown_command_defaults_to_first_token(): void
    {
        $this->assertSame(['python'], BashArity::prefix(['python']));
        // python is arity 2 but the table doesn't have `python script.py`,
        // so we use the arity-2 default → ['python', 'script.py'].
        $this->assertSame(['python', 'script.py'], BashArity::prefix(['python', 'script.py']));
    }

    public function test_empty_returns_empty(): void
    {
        $this->assertSame([], BashArity::prefix([]));
    }

    public function test_label_helper(): void
    {
        $this->assertSame('git checkout', BashArity::label('git checkout main -B'));
        $this->assertSame('docker compose up', BashArity::label('docker compose up -d nginx'));
        $this->assertSame('npm run dev', BashArity::label('npm run dev'));
        $this->assertSame('', BashArity::label(''));
    }

    public function test_kubectl_subcommands(): void
    {
        $this->assertSame(['kubectl', 'get'], BashArity::prefix(['kubectl', 'get', 'pods']));
        $this->assertSame(['kubectl', 'rollout', 'restart'], BashArity::prefix(['kubectl', 'rollout', 'restart', 'deploy/api']));
    }

    public function test_gcloud_3_arity(): void
    {
        $this->assertSame(['gcloud', 'compute', 'instances'], BashArity::prefix(['gcloud', 'compute', 'instances', 'list']));
        $this->assertSame(['aws', 's3', 'ls'], BashArity::prefix(['aws', 's3', 'ls', 's3://bucket']));
    }
}
