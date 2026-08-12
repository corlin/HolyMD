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
final readonly class GeoController {
    public function __construct(private ArticleRepository $articles, private GeoReviewService $reviews, private GeoProposalStore $proposals, private AdminGuard $guard, private Csrf $csrf) {}
    public function review(ServerRequest $request): Response {
        if (($failure = $this->authorize($request)) !== null) return $failure;
        $slug = $this->slug($request->path); if ($slug === null) return Response::json(['error' => 'Invalid article route.'], 404);
        try { $result = $this->reviews->review($this->articles->read($slug)); foreach ($result->proposals as $proposal) $this->proposals->save($proposal); return Response::json(['articleSlug' => $result->articleSlug, 'bodyHash' => $result->bodyHash, 'findings' => $result->findings, 'proposals' => array_map(static fn (GeoProposal $p): array => ['id' => $p->id->value, 'type' => $p->type, 'value' => $p->value, 'status' => $p->status], $result->proposals)]); }
        catch (InvalidArgumentException $exception) { return Response::json(['error' => $exception->getMessage()], 422); }
    }
    public function accept(ServerRequest $request): Response { return $this->decision($request, true); }
    public function reject(ServerRequest $request): Response { return $this->decision($request, false); }
    public function edit(ServerRequest $request): Response {
        if (($failure = $this->authorize($request)) !== null) return $failure;
        try { $id = new GeoProposalId($this->proposalId($request->path)); $proposal = $this->proposals->get($id); $value = $request->input('value'); if (!is_string($value) && !is_array($value)) throw new InvalidArgumentException('Proposal value must be JSON text or an object.'); if (is_array($value)) { foreach (array_keys($value) as $key) if (in_array(strtolower((string) $key), ['body', 'content', 'markdown', 'body_markdown', 'rewrite'], true)) throw new InvalidArgumentException('Proposal edits may not contain body content.'); } $this->proposals->save(new GeoProposal($proposal->id, $proposal->articleSlug, $proposal->bodyHash, $proposal->type, $value)); return Response::json(['saved' => true]); }
        catch (InvalidArgumentException|LogicException $exception) { return Response::json(['error' => $exception->getMessage()], 422); }
    }
    private function decision(ServerRequest $request, bool $accept): Response {
        if (($failure = $this->authorize($request)) !== null) return $failure;
        try { $id = new GeoProposalId($this->proposalId($request->path)); if ($accept) { $document = (new ProposalAcceptance($this->articles, $this->proposals))->accept($id); return Response::json(['accepted' => true, 'bodyHash' => hash('sha256', $document->bodyMarkdown), 'frontMatter' => $document->frontMatter->all()]); } $this->proposals->markRejected($id); return Response::json(['rejected' => true]); }
        catch (InvalidArgumentException|LogicException $exception) { return Response::json(['error' => $exception->getMessage()], 422); }
    }
    private function authorize(ServerRequest $request): ?Response { try { $this->guard->requireAdministrator(); } catch (Unauthorized) { return Response::json(['error' => 'Administrator authentication is required.'], 401); } return $this->csrf->valid($request) ? null : Response::json(['error' => 'CSRF token is invalid.'], 419); }
    private function slug(string $path): ?string { return preg_match('#^/admin/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/geo/review$#', $path, $m) === 1 ? $m[1] : null; }
    private function proposalId(string $path): string { if (preg_match('#^/admin/geo/proposals/([A-Za-z0-9][A-Za-z0-9_-]{0,127})/(?:accept|reject|edit)$#', $path, $m) !== 1) throw new InvalidArgumentException('Invalid GEO proposal route.'); return $m[1]; }
}
