<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['message'=>'Tags fetched successfully','data'=>Tag::where(fn($q)=>$q->where('tenant_id',$request->user()->tenant_id)->orWhereNull('tenant_id'))->with('category')->withCount('contacts')->orderBy('name')->get()]);
    }
    public function store(Request $request)
    {
        $tenantId=(int)$request->user()->tenant_id;$data=$request->validate(['name'=>['required','string','max:120',Rule::unique('tags')->where('tenant_id',$tenantId)],'tag_category_id'=>['nullable',Rule::exists('tag_categories','id')->where('tenant_id',$tenantId)],'description'=>'nullable|string|max:1000','color'=>'nullable|string|max:20']);$tag=Tag::create($data+['tenant_id'=>$tenantId]);return response()->json(['message'=>'Tag created','data'=>$tag->load('category')],201);
    }
    public function show(Request $request,string $id){return response()->json(Tag::where('tenant_id',$request->user()->tenant_id)->with(['category','contacts'])->findOrFail($id));}
    public function update(Request $request,string $id)
    {
        $tenantId=(int)$request->user()->tenant_id;$tag=Tag::where('tenant_id',$tenantId)->findOrFail($id);$data=$request->validate(['name'=>['required','string','max:120',Rule::unique('tags')->where('tenant_id',$tenantId)->ignore($tag->id)],'tag_category_id'=>['nullable',Rule::exists('tag_categories','id')->where('tenant_id',$tenantId)],'description'=>'nullable|string|max:1000','color'=>'nullable|string|max:20']);$tag->update($data);return response()->json(['message'=>'Tag updated','data'=>$tag->load('category')]);
    }
    public function destroy(Request $request,string $id)
    {
        $tag=Tag::where('tenant_id',$request->user()->tenant_id)->withCount('contacts')->findOrFail($id);if($tag->contacts_count>0)return response()->json(['message'=>'This tag is still used by contacts. Remove it from contacts before deleting it.','usage_count'=>$tag->contacts_count],422);$tag->delete();return response()->json(['message'=>'Tag deleted']);
    }
}
