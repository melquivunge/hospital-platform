<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Role;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * SaaS operator creates a client and its initial network admin. The operator gets no membership,
 * so provisioning grants no operational access. No password is set or printed: the admin uses
 * "Esqueci a senha", which also proves control of the email address.
 */
class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision
        {name : Nome do cliente (rede ou hospital independente)}
        {--admin-email= : E-mail do administrador inicial}
        {--admin-name= : Nome do administrador, se a conta ainda não existir}';

    protected $description = 'Cria um cliente e seu administrador de rede inicial';

    public function handle(): int
    {
        $name = $this->argument('name');
        $email = $this->option('admin-email');

        if (! is_string($name) || ! is_string($email)) {
            $this->error('Informe o nome do cliente e --admin-email.');

            return self::FAILURE;
        }

        $name = trim($name);
        $email = Str::lower(trim($email));

        $validator = Validator::make(['name' => $name, 'admin_email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $slug = Str::slug($name);

        if ($slug === '') {
            $this->error('O nome precisa conter letras ou números.');

            return self::FAILURE;
        }

        try {
            $tenant = DB::transaction(function () use ($name, $slug, $email) {
                $tenant = Tenant::create(['name' => $name, 'slug' => $slug]);

                $admin = User::firstOrCreate(['email' => $email], [
                    'name' => $this->option('admin-name') ?: Str::before($email, '@'),
                    'password' => Str::password(64),
                ]);

                $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id]);
                $membership->roleAssignments()->create(['tenant_id' => $tenant->id, 'role' => Role::NetworkAdmin]);

                AuditEvent::record('tenant.provisioned', $tenant->id, $tenant, [
                    'admin_user_id' => $admin->id,
                    'role' => Role::NetworkAdmin->value,
                    'source' => 'console',
                ]);

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            $this->error("Já existe um cliente com o identificador \"{$slug}\". Nada foi alterado.");

            return self::FAILURE;
        }

        $this->info("Cliente \"{$tenant->name}\" criado (#{$tenant->id}).");
        $this->line('O administrador deve definir a senha em "Esqueci a senha" na tela de login.');

        return self::SUCCESS;
    }
}
