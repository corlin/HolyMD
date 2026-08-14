<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use HolyMD\Auth\AdminGuard;
use HolyMD\Auth\Unauthorized;
use HolyMD\Content\ArticleRepository;
use HolyMD\Http\Csrf;
use HolyMD\Http\Response;
use HolyMD\Http\ServerRequest;
use InvalidArgumentException;
use LogicException;
use HolyMD\Queue\MySqlJobQueue;
use HolyMD\Admin\VersionService;
final readonly class GeoController {
    public function __construct(private ArticleRepository $articles, private GeoReviewService $reviews, private GeoProposalStore $proposals, private AdminGuard $guard, private Csrf $csrf, private ?MySqlJobQueue $queue = null, private ?VersionService $versions = null) {}
    public function review(ServerRequest $request): Response {
        if (($failure = $this->authorize($request)) !== null) return $failure;
        $slug = $this->slug($request->path); if ($slug === null) return Response::json(['error' => 'Invalid article route.'], 404);
        try { $document = $this->articles->read($slug); if ($this->queue !== null && $this->versions !== null) { $version = $this->versions->snapshot($document); $jobId = $this->queue->enqueueGeoReview($document, $version->value . '.md'); return Response::json(['articleSlug' => $slug, 'queued' => true, 'jobId' => $jobId], 202); } $result = $this->reviews->review($document); if ($this->proposals instanceof MySqlGeoProposalStore && $this->versions !== null) { $proposals = $this->proposals->persistSyncReview($document, $this->versions->snapshot($document)->value, $result); } else { foreach ($result->proposals as $proposal) $this->proposals->save($proposal); $proposals = $result->proposals; } return Response::json(['articleSlug' => $result->articleSlug, 'bodyHash' => $result->bodyHash, 'findings' => $result->findings, 'proposals' => array_map(static fn (GeoProposal $p): array => ['id' => $p->id->value, 'type' => $p->type, 'value' => $p->value, 'status' => $p->status], $proposals)]); }
        catch (InvalidArgumentException $exception) { return Response::json(['error' => $exception->getMessage()], 422); }
    }
    public function status(ServerRequest $request): Response {
        try { $this->guard->requireAdministrator(); } catch (Unauthorized) { return Response::json(['error' => 'Administrator authentication is required.'], 401); }
        $slug = $this->slug($request->path); if ($slug === null) return Response::json(['error' => 'Invalid article route.'], 404);
        if (!$this->proposals instanceof MySqlGeoProposalStore) return Response::json(['error' => 'Queued GEO status is not configured.'], 404);
        $status = $this->proposals->latestForArticle($slug); return $status === null ? Response::json(['status' => 'none', 'proposals' => []]) : Response::json($status);
    }
    public function accept(ServerRequest $request): Response { return $this->decision($request, true); }
    public function reject(ServerRequest $request): Response { return $this->decision($request, false); }
    public function edit(ServerRequest $request): Response {
        if (($failure = $this->authorize($request)) !== null) return $failure;
        try { $id = new GeoProposalId($this->proposalId($request->path)); $proposal = $this->proposals->get($id); $submitted = $request->input('value'); if (!is_string($submitted)) throw new InvalidArgumentException('Proposal edit value is required.'); if (is_string($proposal->value)) { $value = $submitted; } else { try { $value = json_decode($submitted, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new InvalidArgumentException('Proposal value must be valid JSON.'); } if (!$this->validEditedValue($proposal->type, $value)) throw new InvalidArgumentException('Proposal JSON does not match the proposal type.'); } $this->proposals->save(new GeoProposal($proposal->id, $proposal->articleSlug, $proposal->inputChecksum, $proposal->type, $value, $proposal->status, $proposal->bodyHash)); return Response::json(['saved' => true, 'value' => $value]); }
        catch (InvalidArgumentException|LogicException $exception) { return Response::json(['error' => $exception->getMessage()], 422); }
    }
    private function decision(ServerRequest $request, bool $accept): Response {
        if (($failure = $this->authorize($request)) !== null) return $failure;
        try { $id = new GeoProposalId($this->proposalId($request->path)); if ($accept) { $proposal = $this->proposals->get($id); if ($proposal->status !== 'pending') throw new LogicException('Only pending GEO proposals can be accepted.'); $this->proposals->markAccepted($id, $proposal->inputChecksum); return Response::json(['accepted' => true]); } $this->proposals->markRejected($id); return Response::json(['rejected' => true]); }
        catch (InvalidArgumentException|LogicException $exception) { return Response::json(['error' => $exception->getMessage()], 422); }
    }
    private function authorize(ServerRequest $request): ?Response { try { $this->guard->requireAdministrator(); } catch (Unauthorized) { return Response::json(['error' => 'Administrator authentication is required.'], 401); } return $this->csrf->valid($request) ? null : Response::json(['error' => 'CSRF token is invalid.'], 419); }
    private function slug(string $path): ?string { return preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/geo/review$#', $path, $m) === 1 ? $m[1] : null; }
    private function proposalId(string $path): string { if (preg_match('#^/admin/geo/proposals/([A-Za-z0-9][A-Za-z0-9_-]{0,127})/(?:accept|reject|edit)$#', $path, $m) !== 1) throw new InvalidArgumentException('Invalid GEO proposal route.'); return $m[1]; }
    private function validEditedValue(string $type, mixed $value): bool { if (!is_array($value) || $this->forbidden($value)) return false; if (in_array($type, ['entities','faq_candidates','sources','internal_links'], true)) return array_is_list($value) && array_reduce($value, static fn(bool $ok,mixed $item): bool=>$ok&&is_string($item), true); return !array_is_list($value); }
    private function forbidden(array $value): bool { foreach ($value as $key=>$item) { if (is_string($key) && in_array(strtolower((string) preg_replace('/[^a-z0-9_]/i','',$key)), ['body','content','markdown','body_markdown','bodymarkdown','rewrite'], true)) return true; if (is_array($item) && $this->forbidden($item)) return true; } return false; }
}
