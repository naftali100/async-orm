# async-orm

async orm lib inspired by [redbean](https://redbeanphp.com)

# usage

almost the same as redbean. notice the `yield` every time you do db call

```php
Amp\Loop::run(function(){
    yield ORM::connect('127.0.0.1', 'user', 'pass', 'db');

    $user = ORM::create('user');
    $user->name = 'jon';
    $userid = yield ROM::store($user);

    $same_user = yield ORM::load('user', $userid);
    print $same_user->id; // id
    print $same_user->name; // jon
});
```

## todo

- [ V ] tests
- [ ] relations
- [ ] fix structure
