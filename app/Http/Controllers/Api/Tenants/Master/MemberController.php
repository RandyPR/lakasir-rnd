<?php

namespace App\Http\Controllers\Api\Tenants\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenants\Member;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\QueryBuilder;

class MemberController extends Controller
{
    /** The columns a client may set; `guarded` only protects `id`, so the request is never filled whole. */
    private const COLUMNS = ['name', 'code', 'address', 'email', 'identity_type', 'identity_number', 'joined_date'];

    public function index()
    {
        $members = QueryBuilder::for(Member::class)
            ->allowedFilters(['name', 'email'])
            ->orderByDesc('created_at')
            ->get();

        return $this->success($members);
    }

    public function store(Request $request)
    {
        $this->validate($request, $this->rules(new Member));
        $member = new Member();
        $member->fill($request->only(self::COLUMNS));
        $member->save();

        return $this->success([], "success creating items");
    }

    public function show(Member $member)
    {
        return $this->success($member);
    }

    public function update(Request $request, Member $member)
    {
        $this->validate($request, $this->rules($member));
        $member->fill($request->only(self::COLUMNS));
        $member->update();

        return $this->success([], "success updating items");
    }

    public function destroy(Member $member)
    {
        $member->delete();

        return $this->success([], "success deleting items");
    }

    private function rules(?Member $member): array
    {
        return [
            "name" => ["required", "min:3"],
            "email" => [Rule::unique("members")->ignore($member->id), "nullable"],
        ];
    }
}
