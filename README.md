# Stash Redis Driver

This is a RedisDriver for the PHP Caching Library [Stash](https://github.com/tedious/stash).

The original driver included in the project has 2 problems that are solved with this driver:

* When deleting the stackparent of a key the subkeys are also actively deleted. This happens in a semi-atomic way. That means that as long as the operation does not fail halfway through you can assume that all data has been removed.

* The keys that are being generated use a standard Redis pattern and are more easily identifiable than the current normalized keys which are hashed.

  In practice this means that it ```/test/key/with/stack/groups/``` will be converted to ```test:key:with:stack:groups```. If you decide to delete the stackparent ```/test/key``` and reinsert the first key it will be saved as ```test:key_1:with:stack:groups``` and all the keys with the previous structure will be deleted.
  
Another reason for making this repository instead of adding it to the project itself (at the time) is that I believe a package should always explicitly specify it's dependencies.

Therefore the Drivers (which depend on third party software) should be in seperate repositories that clearly state their dependency and should inversely depend on the library in order to decouple them.

It remains to be seen if this implementation will take the place of the current driver in the Stash project. That will become clear after communication with the project leaders.

# Extra Options
This package adds the following options

## ```normalize_keys```
**default value**: false

***Note*** If set to false it is illegal to use ```:``` and ```_``` as part of your keys. Just define them as ```/part/of/a/nice/key/``` without using underscores and you'll be fine. 

If this is true all the parts of the keys will be normalized with the default Stash key normalization strategy. In practice this means that all the parts will be converted to md5 hashes. Warning: This may result in pretty long keys, a more intelligent normalization strategy might be required in those cases.

 
