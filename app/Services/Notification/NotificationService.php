<?php

namespace App\Services\Notification;

use App\Services\Firebase\FirestoreService;
use App\Services\FcmService;
use App\Services\AppLogger;
use Carbon\Carbon;
use App\Services\Notification\TriggerResolver;
use App\Repositories\Firestore\NotificationRepository;
use Exception;

class NotificationService
{
  protected $firestore;
  protected $notificationRepo;
  protected $fcm;
  protected $triggerResolver;

  public function __construct(
    FirestoreService $firestore,
    FcmService $fcm,
    TriggerResolver $triggerResolver,
    NotificationRepository $notificationRepo,
  ) {
    $this->firestore = $firestore;
    $this->notificationRepo = $notificationRepo;
    $this->fcm = $fcm;
    $this->triggerResolver = $triggerResolver;
  }

  public function processChunk($lastDoc = null)
  {
    AppLogger::info("Processing chunk | cursor=" . ($lastDoc ?? 'null'));
    AppLogger::info("Processing chunk | page= " . ($lastDoc ? 'greater than 1' : '1'));

    $limit = 100;

    $users = $this->notificationRepo->getNotificationUsers($limit, $lastDoc);

    if (empty($users)) {
      AppLogger::info("No users found in chunk");
      return ['count' => 0];
    }

    $processed = 0;

    foreach ($users as $user) {

      if (!empty($user['lastActiveOn'])) {
        AppLogger::info("Proccessing...");
        try {
          $lastActive = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $user['lastActiveOn']
          );

          if ($lastActive->diffInMinutes(now()) < 30) {
            continue;
          }
        } catch (Exception $e) {
          AppLogger::warning("Invalid date format | user={$user['id']}");
          continue;
        }
      }

      $this->processSingleUser($user);
    }

    AppLogger::info("Chunk processed | count={$processed}");

    return [
      'count' => $processed,
      'last_doc' => end($users)['id']
    ];
  }

  public function processSingleUser(array $user)
  {
    try {
      $userId = $user['id'];

      if (empty($user['fcm'])) {
        AppLogger::debug("Skip user {$userId} | no token");
        return;
      }

      if ($this->isUserActive($user)) {
        AppLogger::debug("Skip user {$userId} | active");
        return;
      }

      if ($this->sentRecently($userId)) {
        AppLogger::debug("Skip user {$userId} | sent recently");
        return;
      }

      $trigger = $this->triggerResolver->resolve($user);
      AppLogger::debug("Trigger recieved" . empty($trigger));
      if (!$trigger) {
        AppLogger::debug("Skip user {$userId} | no trigger");
        return;
      }

      AppLogger::info("Trigger selected | user={$userId} | trigger={$trigger['id']}");

      $this->send($user, $trigger);
    } catch (\Exception $e) {
      AppLogger::error("User processing failed | user={$user['id']} | error=" . $e->getMessage());
    }
  }

  protected function isUserActive($user): bool
  {
    if (empty($user['lastActiveOn'])) return false;

    return Carbon::parse($user['lastActiveOn'])->diffInMinutes(now()) < 30;
  }

  protected function sentRecently($userId): bool
  {
    $query = $this->firestore->buildQuery(
      'notifications',
      [
        ['field' => 'user_id', 'op' => 'EQUAL', 'value' => $userId],
        [
          'field' => 'sent_at',
          'op' => 'GREATER_THAN_OR_EQUAL',
          'value' => now()->subHours(4)->toISOString()
        ]
      ],
      1
    );

    return !empty($this->firestore->runQuery($query));
  }

  protected function send($user, $trigger)
  {
    try {
      // sendNotification($title, $body, $fcmToken, $data)
      $response = $this->fcm->sendNotification($trigger['title'], $trigger['body'], $user['fcm'], [
        'type' => $trigger['id'],
        'deep_link' => $trigger['deep_link'],
        'user_id' => $user['id'],
      ]);

      // $response = $this->fcm->sendFcmNotification(
      //   $user['fcm'],
      //   $trigger['title'],
      //   $trigger['body'],
      //   [
      //     'type' => $trigger['id'],
      //     'deep_link' => $trigger['deep_link'],
      //     'user_id' => $user['id'],
      //   ]
      // );

      usleep(50000);

      $status = $response['result'] === FALSE ? 'failed' : 'sent';

      AppLogger::info("FCM {$status} | user={$user['id']} | trigger={$trigger['id']}");

      $this->notificationRepo->create([
        'user_id' => $user['id'],
        'trigger_id' => $trigger['id'],
        'title' => $trigger['title'],
        'body' => $trigger['body'],
        'deep_link' => $trigger['deep_link'],
        'status' => $status,
        'sent_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
      ]);
    } catch (Exception $e) {
      AppLogger::error("FCM send failed | user={$user['id']} | error=" . $e->getMessage());
    }
  }
}
