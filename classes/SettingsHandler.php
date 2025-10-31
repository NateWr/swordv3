<?php

namespace APP\plugins\generic\swordv3\classes;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\swordv3\classes\exceptions\DepositsNotAccepted;
use APP\plugins\generic\swordv3\classes\jobs\Deposit;
use APP\plugins\generic\swordv3\swordv3Client\Client;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationFailed;
use APP\plugins\generic\swordv3\swordv3Client\exceptions\AuthenticationUnsupported;
use APP\plugins\generic\swordv3\swordv3Client\ServiceDocument;
use APP\plugins\generic\swordv3\swordv3Client\StatusDocument;
use APP\plugins\generic\swordv3\Swordv3Plugin;
use APP\publication\Publication;
use DateTime;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\LazyCollection;
use PKP\security\authorization\CanAccessSettingsPolicy;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;
use Throwable;

class SettingsHandler extends Handler
{
    public const STATUS_READY = 'ready';
    public const STATUS_DEPOSITED = 'deposited';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_UNKNOWN = 'unknown';

    public const VALID_STATUSES = [
        self::STATUS_READY,
        self::STATUS_DEPOSITED,
        self::STATUS_REJECTED,
        self::STATUS_DELETED,
        self::STATUS_UNKNOWN,
    ];

    public const PER_PAGE = 50;

    public Swordv3Plugin $plugin;

    public function __construct(Swordv3Plugin $plugin)
    {
        $this->plugin = $plugin;

        $this->addRoleAssignment(
            [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER],
            [
                'saveServiceForm',
                'deposit',
                'csv',
                'getPublications',
                'overview',
                'statusDocument',
                'redeposit',
                'reset',
            ],
        );
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        $this->addPolicy(new CanAccessSettingsPolicy());

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Save the service setup form
     */
    public function saveServiceForm($args, Request $request): void
    {
        $params = $request->getUserVars();

        $errors = [];

        $maxStringLength = 1024;
        $name = trim($params['name'] ?? '');
        $url = trim($params['url'] ?? '');
        $authMode = trim($params['authMode'] ?? '');
        $username = trim($params['username'] ?? '');
        $password = trim($params['password'] ?? '');
        $apiKey = trim($params['apiKey'] ?? '');

        if (!$name) $errors['name'] = [__('validator.required')];
        if (!$url) $errors['url'] = [__('validator.required')];
        if (!$authMode) $errors['authMode'] = [__('validator.required')];

        if (strlen($name) > $maxStringLength) $errors['name'] = [__('validator.max.string', $maxStringLength)];
        if (strlen($url) > $maxStringLength) $errors['url'] = [__('validator.max.string', $maxStringLength)];

        if (!in_array($authMode, ['Basic', 'APIKey'])) {
            $errors['authMode'] = [__('validation.invalidOption')];
        }

        if ($authMode === 'Basic') {
            if (!$username) $errors['username'] = [__('validator.required')];
            if (!$password) $errors['password'] = [__('validator.required')];
        } else if ($authMode === 'APIKey') {
            if (!$apiKey) $errors['apiKey'] = [__('validator.required')];
            if (strlen($apiKey) > $maxStringLength) $errors['apiKey'] = [__('validator.max.string', $maxStringLength)];
        }

        if (count($errors)) {
            response()->json($errors, Response::HTTP_BAD_REQUEST)->send();
            exit();
        }

        $services = $this->plugin->getSetting($request->getContext()->getId(), 'services');
        if (is_null($services) || !is_array($services)) {
            $services = [];
        }

        if (count($services)) {
            $service = $services[0];
        }

        $data = [
            'name' => $name,
            'url' => $url,
            'authMode' => $authMode,
            'enabled' => true,
            'statusMessage' => isset($service['statusMessage']) ? $service['statusMessage'] : '',
        ];

        // Encrypt a new password/api key if it has changed
        if ($authMode === 'Basic') {
            $data['username'] = $username;
            $data['password'] = isset($service['password']) && $service['password'] === $password
                ? $service['password']
                : Crypt::encrypt($password);
        } else if ($authMode === 'APIKey') {
            $data['apiKey'] = isset($service['apiKey']) && $service['apiKey'] === $apiKey
                ? $service['apiKey']
                : Crypt::encrypt($apiKey);
        }

        $service = $this->plugin->getServiceFromPluginSettings($data);

        try {
            $serviceDocument = $this->getServiceDocument($service);
            if (!$serviceDocument->supportsAuthMode($service->authMode->getMode())) {
                throw new AuthenticationUnsupported($service, $serviceDocument);
            }
            if (!$serviceDocument->acceptDeposits()) {
                throw new DepositsNotAccepted($service, $serviceDocument);
            }
        } catch (AuthenticationUnsupported $exception) {
            $nameAuthMode = $authMode === 'Basic'
                ? __('plugins.generic.swordv3.service.authMode.basic')
                : __('plugins.generic.swordv3.service.authMode.apiKey');
            $errors['authMode'] = [__(
                'plugins.generic.swordv3.service.authMode.unsupported',
                ['authMode' => $nameAuthMode]
            )];
        } catch (AuthenticationFailed $exception) {
            if ($authMode === 'Basic') {
                $errors['username'] = [__('plugins.generic.swordv3.service.authMode.userPassFailed')];
            } else {
                $errors['apiKey'] = [__('plugins.generic.swordv3.service.authMode.apiKeyFailed')];
            }
        } catch (RequestException|ConnectException $exception) {
            $errors['url'] = [__('plugins.generic.swordv3.service.setupFailed')];
        } catch (DepositsNotAccepted $exception) {
            $errors['url'] = [__('plugins.generic.swordv3.service.depositsNotAccepted')];
        } catch (Throwable $exception) {
            throw $exception;
        }

        if (count($errors)) {
            response()->json($errors, Response::HTTP_BAD_REQUEST)->send();
            exit();
        }

        $this->plugin->updateSetting(
            $request->getContext()->getId(),
            'services',
            [$data]
        );

        response()->json($data, Response::HTTP_OK)->send();
    }

    /**
     * Dispatch jobs to deposit all publications that have not yet
     * been deposited
     *
     * This does not create a job for publications with a rejected,
     * deleted or unknown status.
     */
    public function deposit($args, Request $request): void
    {
        if (!$request->getRequestMethod() === 'PUT') {
            response()->json([], Response::HTTP_BAD_REQUEST)->send();
            exit;
        }

        if (!$this->checkCSRF($request)) {
            response()->json([
                'error' => __('api.submissions.403.csrfTokenFailure'),
            ], Response::HTTP_FORBIDDEN)->send();
            exit;
        }

        $context = Application::get()->getRequest()->getContext();

        $services = $this->plugin->getServices($context->getId());
        if (!count($services)) {
            throw new Exception('No SWORDv3 service configured for deposits.');
        }

        // TODO: support more than one service
        /** @var OJSService $service */
        $service = $services[0];

        if ($args[0]) {
            $publicationId = (int) $args[0];
            $publication = Repo::publication()->get($publicationId);

            if (!$publication) {
                response()->json([], Response::HTTP_NOT_FOUND)->send();
                exit;
            }

            $submission = Repo::submission()->get($publication->getData('submissionId'));
            if (!$submission || $submission->getData('contextId') !== $context->getId()) {
                response()->json([], Response::HTTP_NOT_FOUND)->send();
                exit;
            }

            dispatch(
                new Deposit(
                    $publication->getId(),
                    $submission->getId(),
                    $context->getId(),
                    $service->url
                )
            );

            response()->json([], Response::HTTP_OK)->send();
        } else {

            $collector = new Collector($context->getId());
            $deposited = $collector->getWithDepositState(null);

            $rows = $collector->getAllPublications()
                ->filter(function($row) use ($deposited) {
                    return (
                        !$deposited->contains(function($r) use ($row) {
                            return $r->publication_id === $row->publication_id;
                        })
                    );
                })
                ->each(function($row) use ($context, $service) {
                    dispatch(
                        new Deposit(
                            $row->publication_id,
                            $row->submission_id,
                            $context->getId(),
                            $service->url
                        )
                    );
                });

            response()->json(['count' => $rows->count()], Response::HTTP_OK)->send();
        }
    }

    /**
     * Get an overview of the deposit statuses
     */
    public function overview($args, Request $request): void
    {
        $context = $request->getContext();

        $collector = new Collector($context->getId());$collector = new Collector($context->getId());

        $countAll = $collector->getAllPublications()->count();
        $allStatuses = $collector->getWithDepositState(null);

        $counts = [
            self::STATUS_READY => $countAll - $allStatuses->count(),
            Collector::STATUS_QUEUED => 0,
            self::STATUS_DEPOSITED => 0,
            self::STATUS_REJECTED => 0,
            self::STATUS_DELETED => 0,
            self::STATUS_UNKNOWN => 0,
        ];

        $counts = $allStatuses->reduce(function($counts, $row) {
            if (in_array($row->setting_value, [Collector::STATUS_QUEUED])) {
                $counts[Collector::STATUS_QUEUED]++;
            }
            if (in_array($row->setting_value, StatusDocument::SUCCESS_STATES)) {
                $counts[self::STATUS_DEPOSITED]++;
            }
            if (in_array($row->setting_value, [StatusDocument::STATE_REJECTED])) {
                $counts[self::STATUS_REJECTED]++;
            }
            if (in_array($row->setting_value, [StatusDocument::STATE_DELETED])) {
                $counts[self::STATUS_DELETED]++;
            }
            if (!in_array($row->setting_value, array_merge(StatusDocument::STATES, [Collector::STATUS_QUEUED]))) {
                $counts[self::STATUS_UNKNOWN]++;
            }
            return $counts;
        }, $counts);

        response()->json($counts, Response::HTTP_OK)->send();
    }

    /**
     * Get a list of publications with deposit data
     */
    public function getPublications($args, Request $request): void
    {
        $status = $args[0];
        $page = (int) $args[1] ?? 1;
        $context = $request->getContext();

        if (!$context) {
            response()->json([
                'error' => __('api.404.resourceNotFound'),
            ], Response::HTTP_NOT_FOUND)->send();
            exit;
        }

        if (!in_array($status, self::VALID_STATUSES)) {
            response()->json([], Response::HTTP_BAD_REQUEST)->send();
            exit;
        }

        $publicationIds = collect([]);
        $collector = new Collector($context->getId());

        if ($status === self::STATUS_READY) {
            $allPublished = $collector->getAllPublications();
            $allWithStatus = $collector->getWithDepositState(null)->map(fn($row) => $row->publication_id);
            $publicationIds = $allPublished
                ->filter(fn($row) => !$allWithStatus->contains($row->publication_id))
                ->map(fn($row) => $row->publication_id);
        } else if ($status === self::STATUS_UNKNOWN) {
            $allStates = array_merge(StatusDocument::STATES, [Collector::STATUS_QUEUED]);
            $publicationIds = $collector->getWithDepositState(null)
                ->filter(fn($row) => !in_array($row->setting_value, $allStates))
                ->map(fn($row) => $row->publication_id);
        } else {
            $publicationIds = $collector->getWithDepositState($this->getSwordStates($status))
                ->map(fn($row) => $row->publication_id);
        }

        $qb = Repo::publication()
            ->getCollector()
            ->filterByContextIds([$request->getContext()->getId()])
            ->getQueryBuilder()
            ->whereIn('p.publication_id', $publicationIds->values());

        $publications = LazyCollection::make(function () use ($qb, $page) {
                $rows = $qb
                    ->limit(self::PER_PAGE)
                    ->offset(($page - 1) * self::PER_PAGE)
                    ->get();
                foreach ($rows as $row) {
                    yield $row->publication_id => Repo::publication()->dao->fromRow($row);
                }
            })
            ->map(function(Publication $publication) use ($request,$context) {
                return [
                    'id' => $publication->getId(),
                    'title' => $publication->getLocalizedFullTitle(),
                    'version' => $publication->getData('version'),
                    'swordv3DateDeposited' => $publication->getData('swordv3DateDeposited'),
                    'swordv3State' => $publication->getData('swordv3State'),
                    'swordv3StatusDocument' => $publication->getData('swordv3StatusDocument'),
                    'exportStatusDocumentUrl' => $request
                        ->getDispatcher()
                        ->url(
                            $request,
                            Application::ROUTE_PAGE,
                            $context->getPath(),
                            'swordv3',
                            'statusDocument',
                            [$publication->getId()],
                        ),
                ];
            });

        $total = $qb->count();

        response()->json([
            'publications' => $publications,
            'total' => $total,
        ], Response::HTTP_OK)->send();
    }

    /**
     * Re-deposit previously deposited Publications
     *
     * This deposits all items or those items with the passed state.
     * It can be used if the service configuration has changed
     * in some way that may result in a different deposit.
     *
     * For example, if a service begins accepting PDF deposits it
     * might be useful to re-deposit all publications. Or it may
     * be useful to re-deposit all rejected publications if there
     * was a misconfiguration with the depositing service.
     *
     * @param array $args
     * @param string $args[0] Only re-deposit publications with this status. One of: rejected,deleted
     */
    public function redeposit($args, Request $request): void
    {
        if (!$request->getRequestMethod() === 'PUT') {
            response()->json([], Response::HTTP_BAD_REQUEST)->send();
            exit;
        }

        if (!$this->checkCSRF($request)) {
            response()->json([
                'error' => __('api.submissions.403.csrfTokenFailure'),
            ], Response::HTTP_FORBIDDEN)->send();
            exit;
        }

        $context = Application::get()->getRequest()->getContext();

        $services = $this->plugin->getServices($context->getId());
        if (!count($services)) {
            throw new Exception('No SWORDv3 service configured for deposits.');
        }

        // TODO: support more than one service
        /** @var OJSService $service */
        $service = $services[0];

        $states = null;
        if (isset($args[0])) {
            if ($args[0] === 'rejected') {
                $states = [StatusDocument::STATE_REJECTED];
            } else if ($args[0] === 'deleted') {
                $states = [StatusDocument::STATE_DELETED];
            }
            if (is_null($states)) {
                throw new Exception("Redeposit requested for unknown state: {$args[0]}");
            }
        }

        $count = (new Collector($context->getId()))->getWithDepositState($states)
            ->each(function($row) use ($context, $service) {
                dispatch(
                    new Deposit(
                        $row->publication_id,
                        $row->submission_id,
                        $context->getId(),
                        $service->url
                    )
                );
            })
            ->count();

        response()->json(['count' => $count], Response::HTTP_OK)->send();
    }

    /**
     * Export a CSV file with all publications that have a
     * deposit status document
     */
    public function csv($args, Request $request): void
    {
        $context = Application::get()->getRequest()->getContext();

        $columns = [
            'contextId' => 'Context',
            'submissionId' => 'Submission',
            'publicationId' => 'Publication',
            'swordv3DateDeposited' => 'Date Deposited',
            'swordv3State' => 'State',
            'swordv3StatusDocument' => 'StatusDocument',
        ];

        $rows = (new Collector($context->getId()))->getDepositDetails();
        $rows = array_map(function($row) use ($columns) {
            $cols = [];
            foreach ($columns as $key => $name) {
                $cols[$key] = isset($row[$key]) ? $row[$key] : '';
            }
            return $cols;
        }, $rows);

        $filename = 'swordv3-export-' . (new DateTime())->format('Y-m-d-h-i') . '.csv';

        header('Content-Description: File Transfer');
        header('Content-type: text/csv');
        header("Content-Disposition: attachment; filename={$filename}");
        $fh = fopen('php://output', 'wb');
        fputcsv($fh, array_values($columns));
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        flush();
        fclose($fh);
    }

    /**
     * Serve the StatusDocument as a JSON file for a specific deposit
     */
    public function statusDocument($args, Request $request): void
    {
        $context = Application::get()->getRequest()->getContext();
        $publicationId = (int) $args[0];
        $publication = Repo::publication()->get($publicationId);

        if (!$publication) {
            response()->json([], Response::HTTP_NOT_FOUND)->send();
        }

        $submission = Repo::submission()->get($publication->getData('submissionId'));
        if (!$submission || $submission->getData('contextId') !== $context->getId()) {
            response()->json([], Response::HTTP_NOT_FOUND)->send();
        }

        $statusDocument = $publication->getData('swordv3StatusDocument');
        if (!$statusDocument) {
            response()->json([], Response::HTTP_NOT_FOUND)->send();

        }

        $filename = 'swordv3-status-' . $publicationId . '-' . (new DateTime())->format('Y-m-d-h-i') . '.json';

        header('Content-Description: File Transfer');
        header('Content-type: application/json');
        header("Content-Disposition: attachment; filename={$filename}");
        echo $statusDocument;
    }

    /**
     * Return the SWORDv3 state that matches on of the statuses
     * used in our UI
     *
     * @param string $status One of the self::STATUS_* constants
     * @return string[] List of StatusDocument::STATE_* constants
     */
    protected function getSwordStates(string $status): array
    {
        switch ($status) {
            case SELF::STATUS_DEPOSITED: return StatusDocument::SUCCESS_STATES;
            case SELF::STATUS_REJECTED: return [StatusDocument::STATE_REJECTED];
            case SELF::STATUS_DELETED: return [StatusDocument::STATE_DELETED];
        }
        return [];
    }

    /**
     * Request a ServiceDocument from a service
     */
    protected function getServiceDocument(OJSService $service): ServiceDocument
    {
        $client = new Client(
            httpClient: Application::get()->getHttpClient(),
            service: $service,
        );
        return $client->getServiceDocument();
    }

    /**
     * Check the CSRF token as it is passed by the useFetch
     * composable for API requests
     */
    protected function checkCSRF(Request $request): bool
    {
        $csrf = isset($_SERVER['HTTP_X_CSRF_TOKEN'])
            ? $_SERVER['HTTP_X_CSRF_TOKEN']
            : null;

        return $csrf === $request->getSession()->token();
    }
}
