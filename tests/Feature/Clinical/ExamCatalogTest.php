<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Exam;
use App\Models\Membership;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 5.1a (delivery 5): the network exam catalog kept by the network admin, and the Laboratory and Imaging
 * roles, one unit each.
 */
class ExamCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->unit = Unit::factory()->for($this->admin->tenant)->create();
    }

    private function blood(array $overrides = []): array
    {
        return ['type' => 'laboratory', 'name' => ' Hemograma  completo ', 'specimen' => 'Sangue', 'code' => '40304361', 'preparation' => '', 'analytes' => [
            ['name' => 'Hemoglobina', 'unit' => 'g/dL', 'reference_min' => '12', 'reference_max' => '16'],
            ['name' => 'Leucócitos', 'unit' => '/mm³', 'reference_min' => '4000', 'reference_max' => '11000', 'reference_text' => 'Adultos'],
        ], ...$overrides];
    }

    public function test_a_decimal_comma_is_read_and_a_thousands_separator_refused(): void
    {
        $this->actingAs($this->admin->user)->post(route('exams.store'), $this->blood(['analytes' => [['name' => 'Plaquetas', 'reference_min' => '150000', 'reference_max' => '450,000']]]))
            ->assertSessionHasErrorsIn('newExam', ['analytes.0.reference_max' => '"450,000" é ambíguo (milhar ou decimal?). Escreva sem separador de milhar, por exemplo 4000 ou 1,5.']);
        $this->post(route('exams.store'), $this->blood(['analytes' => [['name' => 'Potássio', 'reference_min' => '3,5', 'reference_max' => '5,1']]]))->assertSessionHasNoErrors();
        $this->assertSame(['3.5000', '5.1000'], [DB::table('exam_analytes')->value('reference_min'), DB::table('exam_analytes')->value('reference_max')]);
    }

    public function test_the_admin_keeps_laboratory_exams_with_analytes_and_imaging_exams(): void
    {
        $this->actingAs($this->admin->user)->post(route('exams.store'), $this->blood())->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Exame cadastrado.');
        $exam = Exam::withoutTenantScope()->sole();
        $this->assertSame(['Hemograma completo', 'hemograma completo', null], [$exam->name, $exam->name_key, $exam->preparation]);
        $this->assertSame(['Hemoglobina', 'Leucócitos'], $exam->analytes()->pluck('name')->all());

        // The same name of the same type is refused; of the other type it is another exam.
        $this->post(route('exams.store'), $this->blood(['name' => 'HEMOGRAMA COMPLETO']))->assertSessionHasErrorsIn('newExam', ['name' => 'Já existe um exame deste tipo com este nome.']);
        $this->post(route('exams.store'), ['type' => 'imaging', 'name' => 'Raio X de tórax', 'specimen' => 'Tórax', 'analytes' => [['name' => 'X']]])->assertSessionHasErrorsIn('newExam', ['analytes']);
        $this->post(route('exams.store'), ['type' => 'imaging', 'name' => 'Raio X de tórax', 'specimen' => 'Tórax'])->assertSessionHasNoErrors();
        $this->post(route('exams.store'), $this->blood(['name' => 'Outro', 'analytes' => [['name' => 'A', 'reference_min' => '5', 'reference_max' => '1'], ['name' => 'a']]]))
            ->assertSessionHasErrorsIn('newExam', ['analytes.0.reference_max', 'analytes.0.name', 'analytes.1.name']);
        $this->post(route('exams.store'), $this->blood(['name' => 'Outro', 'code' => '=1+1']))->assertSessionHasErrorsIn('newExam', ['code']);

        // Update: the type never changes; an analyte left out is deactivated, never deleted; a new one joins.
        [$hemoglobin, $leukocytes] = $exam->analytes()->get()->all();
        $this->put(route('exams.update', $exam), [...$this->blood(['type' => 'imaging', 'active' => true]), 'analytes' => [
            ['id' => $leukocytes->id, 'name' => 'Leucócitos totais', 'unit' => '/mm³'],
            ['name' => 'Plaquetas', 'unit' => '/mm³', 'reference_min' => '150000', 'reference_max' => '450000'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('laboratory', $exam->fresh()->type);
        $this->assertSame([[$hemoglobin->id, false], [$leukocytes->id, true]], DB::table('exam_analytes')->whereIn('id', [$hemoglobin->id, $leukocytes->id])->orderBy('id')->get()->map(fn ($row) => [$row->id, (bool) $row->active])->all());
        $this->get(route('exams.index'))->assertInertia(fn (Assert $page) => $page->component('exams/index')->where('total', 2)
            ->where('exams.1.name', 'Hemograma completo')->where('exams.1.analytes', fn ($analytes) => collect($analytes)->pluck('name')->all() === ['Leucócitos totais', 'Plaquetas']));
        // Deactivated, never deleted; an analyte of another exam is not this exam's.
        $other = Exam::withoutTenantScope()->where('type', 'imaging')->sole();
        $this->put(route('exams.update', $other), ['name' => 'Raio X de tórax', 'specimen' => 'Tórax', 'active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->active);
        $this->put(route('exams.update', $exam), [...$this->blood(['active' => true]), 'analytes' => [['id' => 999999, 'name' => 'X']]])->assertSessionHasErrorsIn("exam-{$exam->id}", ['analytes.0.id']);
        $audit = AuditEvent::where('action', 'exam.updated')->where('subject_id', $exam->id)->sole();
        $this->assertEquals(['fields' => [], 'analytes_changed' => true, 'active' => true], $audit->context);
    }

    public function test_analyte_names_can_be_reused_swapped_and_saved_twice(): void
    {
        $this->actingAs($this->admin->user)->post(route('exams.store'), $this->blood())->assertSessionHasNoErrors();
        $exam = Exam::withoutTenantScope()->sole();
        [$hemoglobin, $leukocytes] = $exam->analytes()->get()->all();
        $save = fn (array $analytes) => $this->put(route('exams.update', $exam), [...$this->blood(['active' => true]), 'analytes' => $analytes]);
        $ids = fn () => DB::table('exam_analytes')->where('exam_id', $exam->id)->where('active', true)->orderBy('position')->get()->map(fn ($row) => [$row->id, $row->name])->all();

        // A swap of names between two analytes.
        $save([['id' => $hemoglobin->id, 'name' => 'Leucócitos'], ['id' => $leukocytes->id, 'name' => 'Hemoglobina']])->assertSessionHasNoErrors();
        $this->assertSame([[$hemoglobin->id, 'Leucócitos'], [$leukocytes->id, 'Hemoglobina']], $ids());
        // Removed, then added again by name: the same analyte comes back (results refer to it).
        $save([['id' => $hemoglobin->id, 'name' => 'Leucócitos']])->assertSessionHasNoErrors();
        $save([['id' => $hemoglobin->id, 'name' => 'Leucócitos'], ['name' => 'hemoglobina', 'unit' => 'g/dL']])->assertSessionHasNoErrors();
        $this->assertSame([[$hemoglobin->id, 'Leucócitos'], [$leukocytes->id, 'hemoglobina']], $ids());
        // A new analyte saved twice (the second time with its id) stays one.
        $save([['id' => $hemoglobin->id, 'name' => 'Leucócitos'], ['id' => $leukocytes->id, 'name' => 'hemoglobina'], ['name' => 'Plaquetas']])->assertSessionHasNoErrors();
        $platelets = (int) DB::table('exam_analytes')->where('name', 'Plaquetas')->value('id');
        $save([['id' => $hemoglobin->id, 'name' => 'Leucócitos'], ['id' => $leukocytes->id, 'name' => 'hemoglobina'], ['id' => $platelets, 'name' => 'Plaquetas', 'unit' => '/mm³']])->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('exam_analytes')->where('exam_id', $exam->id)->count());
        // Renaming into a removed analyte's name points to adding it back; a repeated id or a malformed name is refused.
        $save([['id' => $hemoglobin->id, 'name' => 'Leucócitos'], ['id' => $platelets, 'name' => 'Plaquetas']])->assertSessionHasNoErrors();
        $bag = "exam-{$exam->id}";
        $save([['id' => $hemoglobin->id, 'name' => 'Leucócitos'], ['id' => $platelets, 'name' => 'Hemoglobina']])
            ->assertSessionHasErrorsIn($bag, ['analytes.1.name' => 'Este nome é de um analito removido. Adicione-o como novo analito para reativá-lo.']);
        $save([['id' => $hemoglobin->id, 'name' => 'A'], ['id' => $hemoglobin->id, 'name' => 'B']])->assertSessionHasErrorsIn($bag, ['analytes.0.id', 'analytes.1.id']);
        $save([['name' => ['x']]])->assertSessionHasErrorsIn($bag, ['analytes.0.name']);
    }

    public function test_only_the_network_admin_keeps_the_catalog_and_the_database_keeps_its_values(): void
    {
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, $this->unit);
        $this->actingAs($manager->user)->get(route('exams.index'))->assertForbidden();
        $this->post(route('exams.store'), $this->blood())->assertForbidden();

        $this->actingAs($this->admin->user)->post(route('exams.store'), $this->blood())->assertSessionHasNoErrors();
        $exam = (array) DB::table('exams')->sole();
        $analyte = (array) DB::table('exam_analytes')->first();
        foreach ([
            fn () => DB::table('exams')->insert([...$exam, 'id' => $exam['id'] + 1, 'name_key' => 'x', 'type' => 'surgery']),
            fn () => DB::table('exams')->insert([...$exam, 'id' => $exam['id'] + 1, 'name_key' => 'y', 'specimen' => ' ']),
            fn () => DB::table('exam_analytes')->insert([...$analyte, 'id' => $analyte['id'] + 10, 'name' => 'Z', 'reference_min' => 5, 'reference_max' => 1]),
        ] as $wrong) {
            try {
                DB::transaction($wrong);
                $this->fail('The database accepted it.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
    }

    public function test_the_laboratory_and_imaging_roles_need_a_unit(): void
    {
        $person = Membership::factory()->for($this->admin->tenant)->create();
        $person->grant(Role::Laboratory, $this->unit);
        $person->grant(Role::Imaging, $this->unit);
        $this->assertSame(['imaging', 'laboratory'], DB::table('role_assignments')->where('membership_id', $person->id)->orderBy('role')->pluck('role')->all());
        try {
            DB::transaction(fn () => DB::table('role_assignments')->insert(['tenant_id' => $this->admin->tenant_id, 'membership_id' => $person->id, 'role' => 'laboratory', 'unit_id' => null, 'created_at' => now(), 'updated_at' => now()]));
            $this->fail('The database accepted a Laboratory role without a unit.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->actingAs($this->admin->user)->post(route('people.store'), ['name' => 'Ana Lab', 'email' => 'ana.lab@example.test', 'role' => 'laboratory', 'unit_id' => $this->unit->id])->assertSessionHasNoErrors();
    }
}
