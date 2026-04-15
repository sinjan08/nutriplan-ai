<?php

return [

  'credentials' => [
    'file' => env('FIREBASE_CREDENTIALS'),
  ],

  'firestore' => [
    'database' => env('FIRESTORE_DATABASE', '(default)'),
  ],

];

?>