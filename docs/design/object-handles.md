# Object handles (`spl_object_id`, `var_dump` `#N`)

php numbers objects 1, 2, 3 at `new` and REUSES the number of a freed object
(LIFO free list in `objects_store`). Manticore answered the object ADDRESS from
`spl_object_id` and a literal `#1` from `var_dump` (#457, #72).

## Design

- ABI v25: the object header grows 16 -> 24 bytes. Word at `OBJECT_HANDLE_OFFSET`
  (16) holds the handle; 0 = none. `ClassDef::headerSize()` is the only owner of
  the first property offset, so layouts, the init bitmap, `.sig` `hdr` all follow.
  `#[Struct]` values have no header and no handle. Enum-case singletons are
  separate static images (`EnumDef`) and keep an address.
- Table: only a FREE LIST. `links[handle]` (i32) threads freed handles, head in
  `@__mc_oh_free`; fresh handles come from `@__mc_oh_next`. Same shape as Zend.
  `linkonce_odr` globals, so one table across separately linked objects.
- `@__mir_obj_handle_alloc(obj)` is called AFTER the header/template is written
  (the template memcpy zeroes word 2) at every site that builds an object
  (`new`, `clone`, `(object)` casts, JSON stdClass).
- `@__mir_obj_handle_free(obj)` is called at the four real free sites (rc
  release, cycle collector garbage, dead-root shell, mark-root free) right
  before the block goes back to the pool. It zeroes the word, so it is
  idempotent. A destructor that resurrects `$this` keeps its handle (the free
  site is past the `alive` check).
- `spl_object_id` loads the handle when the word at `ptr-8` is `RC_TAG_MAGIC`,
  else keeps the address (enum case, closure env, struct). `var_dump` prints it.

## Cost

One call per `new` (a load, a branch, a store; the grow path is cold) plus 8
bytes of header (pool classes are 16 B, so about half of all classes absorb it).
Numbers: see the commit message / PR.

## Not covered

- Closures print `#1` in `var_dump` (a closure env is not an object here).
- Handle table is not thread-safe (the engine is single threaded).
- A leaked handle (object freed through the shallow `str_release` misroute
  path) is never reused: ids drift from php only after that.

## Related, separate causes

- #475 (private-property write from a child scope): property LOOKUP is not
  scope aware. Unrelated to handles.
- #45 (global destruct order at shutdown): shutdown ordering in the dtor
  registry. Unrelated to handles, except that creation order is now
  observable as the handle.

## Status of the issue cluster

- #457: fixed by the handle (case `object_handle_numbers`).
- #72: the ids are right now; the remaining diff is a separate ownership bug
  (by-ref foreach store never releases the element's old object), filed as #549.
- Owned temporaries handed to `var_dump` (`var_dump(new C)`, `var_dump(f())`) are
  released after the dump; before, the object (and its handle) lived to scope end.
- WeakMap / WeakReference key by ADDRESS (`__mc_obj_addr`), not by handle.
- #475, #45: not handle related, not fixed here.

Cost: 30M `new`+free of a 2-property object, 3M kept: 0.556 s -> 0.592 s (+6%).
