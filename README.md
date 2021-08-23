# async-orm

async orm lib inspired by [redbean](https://redbeanphp.com)

# usage

almost the same as redbean. notice the `yield` every time you do db call

```php
Amp\Loop::run(function(){
    $db = yield orm::connect('127.0.0.1', 'user', 'pass', 'db');

    $user = $db->create('user');
    $user->name = 'jon';
    $userid = yield $db->store($user);

    $same_user = yield $db->load('user', $userid);
    print $same_user->id; // id
    print $same_user->name; // jon
});
```

## todo

- [ ] relations
- [ ] fix stracture
- [ ] tests
