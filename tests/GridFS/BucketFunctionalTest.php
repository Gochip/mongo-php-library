<?php

namespace MongoDB\Tests\GridFS;

use MongoDB\BSON\Binary;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use MongoDB\GridFS\Bucket;
use MongoDB\GridFS\Exception\FileNotFoundException;
use MongoDB\Model\IndexInfo;
use MongoDB\Operation\ListCollections;
use MongoDB\Operation\ListIndexes;

/**
 * Functional tests for the Bucket class.
 */
class BucketFunctionalTest extends FunctionalTestCase
{
    public function testValidConstructorOptions()
    {
        new Bucket($this->manager, $this->getDatabaseName(), [
            'bucketName' => 'test',
            'chunkSizeBytes' => 8192,
            'readConcern' => new ReadConcern(ReadConcern::LOCAL),
            'readPreference' => new ReadPreference(ReadPreference::RP_PRIMARY),
            'writeConcern' => new WriteConcern(WriteConcern::MAJORITY, 1000),
        ]);
    }

    /**
     * @expectedException MongoDB\Exception\InvalidArgumentException
     * @dataProvider provideInvalidConstructorOptions
     */
    public function testConstructorOptionTypeChecks(array $options)
    {
        new Bucket($this->manager, $this->getDatabaseName(), $options);
    }

    public function provideInvalidConstructorOptions()
    {
        $options = [];

        foreach ($this->getInvalidStringValues() as $value) {
            $options[][] = ['bucketName' => $value];
        }

        foreach ($this->getInvalidIntegerValues() as $value) {
            $options[][] = ['chunkSizeBytes' => $value];
        }

        foreach ($this->getInvalidReadConcernValues() as $value) {
            $options[][] = ['readConcern' => $value];
        }

        foreach ($this->getInvalidReadPreferenceValues() as $value) {
            $options[][] = ['readPreference' => $value];
        }

        foreach ($this->getInvalidWriteConcernValues() as $value) {
            $options[][] = ['writeConcern' => $value];
        }

        return $options;
    }

    /**
     * @dataProvider provideInputDataAndExpectedChunks
     */
    public function testDelete($input, $expectedChunks)
    {
        $id = $this->bucket->uploadFromStream('filename', $this->createStream($input));

        $this->assertCollectionCount($this->filesCollection, 1);
        $this->assertCollectionCount($this->chunksCollection, $expectedChunks);

        $this->bucket->delete($id);

        $this->assertCollectionCount($this->filesCollection, 0);
        $this->assertCollectionCount($this->chunksCollection, 0);
    }

    public function provideInputDataAndExpectedChunks()
    {
        return [
            ['', 0],
            ['foobar', 1],
            [str_repeat('a', 261120), 1],
            [str_repeat('a', 261121), 2],
            [str_repeat('a', 522240), 2],
            [str_repeat('a', 522241), 3],
            [str_repeat('foobar', 43520), 1],
            [str_repeat('foobar', 43521), 2],
            [str_repeat('foobar', 87040), 2],
            [str_repeat('foobar', 87041), 3],
        ];
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\FileNotFoundException
     */
    public function testDeleteShouldRequireFileToExist()
    {
        $this->bucket->delete('nonexistent-id');
    }

    /**
     * @dataProvider provideInputDataAndExpectedChunks
     */
    public function testDeleteStillRemovesChunksIfFileDoesNotExist($input, $expectedChunks)
    {
        $id = $this->bucket->uploadFromStream('filename', $this->createStream($input));

        $this->assertCollectionCount($this->filesCollection, 1);
        $this->assertCollectionCount($this->chunksCollection, $expectedChunks);

        $this->filesCollection->deleteOne(['_id' => $id]);

        try {
            $this->bucket->delete($id);
            $this->fail('FileNotFoundException was not thrown');
        } catch (FileNotFoundException $e) {}

        $this->assertCollectionCount($this->chunksCollection, 0);
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\CorruptFileException
     */
    public function testDownloadingFileWithMissingChunk()
    {
        $id = $this->bucket->uploadFromStream("filename", $this->createStream("foobar"));

        $this->chunksCollection->deleteOne(['files_id' => $id, 'n' => 0]);

        stream_get_contents($this->bucket->openDownloadStream($id));
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\CorruptFileException
     */
    public function testDownloadingFileWithUnexpectedChunkIndex()
    {
        $id = $this->bucket->uploadFromStream("filename", $this->createStream("foobar"));

        $this->chunksCollection->updateOne(
            ['files_id' => $id, 'n' => 0],
            ['$set' => ['n' => 1]]
        );

        stream_get_contents($this->bucket->openDownloadStream($id));
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\CorruptFileException
     */
    public function testDownloadingFileWithUnexpectedChunkSize()
    {
        $id = $this->bucket->uploadFromStream("filename", $this->createStream("foobar"));

        $this->chunksCollection->updateOne(
            ['files_id' => $id, 'n' => 0],
            ['$set' => ['data' => new Binary('fooba', Binary::TYPE_GENERIC)]]
        );

        stream_get_contents($this->bucket->openDownloadStream($id));
    }

    /**
     * @dataProvider provideInputDataAndExpectedChunks
     */
    public function testDownloadToStream($input)
    {
        $id = $this->bucket->uploadFromStream('filename', $this->createStream($input));
        $destination = $this->createStream();
        $this->bucket->downloadToStream($id, $destination);

        $this->assertStreamContents($input, $destination);
    }

    /**
     * @expectedException MongoDB\Exception\InvalidArgumentException
     * @dataProvider provideInvalidStreamValues
     */
    public function testDownloadToStreamShouldRequireDestinationStream($destination)
    {
        $this->bucket->downloadToStream('id', $destination);
    }

    public function provideInvalidStreamValues()
    {
        return $this->wrapValuesForDataProvider([null, 123, 'foo', [], hash_init('md5')]);
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\FileNotFoundException
     */
    public function testDownloadToStreamShouldRequireFileToExist()
    {
        $this->bucket->downloadToStream('nonexistent-id', $this->createStream());
    }

    public function testDownloadToStreamByName()
    {
        $this->bucket->uploadFromStream('filename', $this->createStream('foo'));
        $this->bucket->uploadFromStream('filename', $this->createStream('bar'));
        $this->bucket->uploadFromStream('filename', $this->createStream('baz'));

        $destination = $this->createStream();
        $this->bucket->downloadToStreamByName('filename', $destination);
        $this->assertStreamContents('baz', $destination);

        $destination = $this->createStream();
        $this->bucket->downloadToStreamByName('filename', $destination, ['revision' => -3]);
        $this->assertStreamContents('foo', $destination);

        $destination = $this->createStream();
        $this->bucket->downloadToStreamByName('filename', $destination, ['revision' => -2]);
        $this->assertStreamContents('bar', $destination);

        $destination = $this->createStream();
        $this->bucket->downloadToStreamByName('filename', $destination, ['revision' => -1]);
        $this->assertStreamContents('baz', $destination);

        $destination = $this->createStream();
        $this->bucket->downloadToStreamByName('filename', $destination, ['revision' => 0]);
        $this->assertStreamContents('foo', $destination);

        $destination = $this->createStream();
        $this->bucket->downloadToStreamByName('filename', $destination, ['revision' => 1]);
        $this->assertStreamContents('bar', $destination);

        $destination = $this->createStream();
        $this->bucket->downloadToStreamByName('filename', $destination, ['revision' => 2]);
        $this->assertStreamContents('baz', $destination);
    }

    /**
     * @expectedException MongoDB\Exception\InvalidArgumentException
     * @dataProvider provideInvalidStreamValues
     */
    public function testDownloadToStreamByNameShouldRequireDestinationStream($destination)
    {
        $this->bucket->downloadToStreamByName('filename', $destination);
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\FileNotFoundException
     * @dataProvider provideNonexistentFilenameAndRevision
     */
    public function testDownloadToStreamByNameShouldRequireFilenameAndRevisionToExist($filename, $revision)
    {
        $this->bucket->uploadFromStream('filename', $this->createStream('foo'));
        $this->bucket->uploadFromStream('filename', $this->createStream('bar'));

        $destination = $this->createStream();
        $this->bucket->downloadToStreamByName($filename, $destination, ['revision' => $revision]);
    }

    public function provideNonexistentFilenameAndRevision()
    {
        return [
            ['filename', 2],
            ['filename', -3],
            ['nonexistent-filename', 0],
            ['nonexistent-filename', -1],
        ];
    }

    public function testDrop()
    {
        $this->bucket->uploadFromStream('filename', $this->createStream('foobar'));

        $this->assertCollectionCount($this->filesCollection, 1);
        $this->assertCollectionCount($this->chunksCollection, 1);

        $this->bucket->drop();

        $this->assertCollectionDoesNotExist($this->filesCollection->getCollectionName());
        $this->assertCollectionDoesNotExist($this->chunksCollection->getCollectionName());
    }

    public function testFind()
    {
        $this->bucket->uploadFromStream('a', $this->createStream('foo'));
        $this->bucket->uploadFromStream('b', $this->createStream('foobar'));
        $this->bucket->uploadFromStream('c', $this->createStream('foobarbaz'));

        $cursor = $this->bucket->find(
            ['length' => ['$lte' => 6]],
            [
                'projection' => [
                    'filename' => 1,
                    'length' => 1,
                    '_id' => 0,
                ],
                'sort' => ['length' => -1],
            ]
        );

        $expected = [
            ['filename' => 'b', 'length' => 6],
            ['filename' => 'a', 'length' => 3],
        ];

        $this->assertSameDocuments($expected, $cursor);
    }

    public function testGetDatabaseName()
    {
        $this->assertEquals($this->getDatabaseName(), $this->bucket->getDatabaseName());
    }

    public function testGetIdFromStream()
    {
        $id = $this->bucket->uploadFromStream('filename', $this->createStream('foobar'));
        $stream = $this->bucket->openDownloadStream($id);

        $this->assertEquals($id, $this->bucket->getIdFromStream($stream));
    }

    /**
     * @dataProvider provideInputDataAndExpectedChunks
     */
    public function testOpenDownloadStream($input)
    {
        $id = $this->bucket->uploadFromStream('filename', $this->createStream($input));

        $this->assertStreamContents($input, $this->bucket->openDownloadStream($id));
    }

    /**
     * @dataProvider provideInputDataAndExpectedChunks
     */
    public function testOpenDownloadStreamAndMultipleReadOperations($input)
    {
        $id = $this->bucket->uploadFromStream('filename', $this->createStream($input));
        $stream = $this->bucket->openDownloadStream($id);
        $buffer = '';

        while (strlen($buffer) < strlen($input)) {
            $expectedReadLength = min(4096, strlen($input) - strlen($buffer));
            $buffer .= $read = fread($stream, 4096);

            $this->assertInternalType('string', $read);
            $this->assertEquals($expectedReadLength, strlen($read));
        }

        $this->assertTrue(fclose($stream));
        $this->assertEquals($input, $buffer);
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\FileNotFoundException
     */
    public function testOpenDownloadStreamShouldRequireFileToExist()
    {
        $this->bucket->openDownloadStream('nonexistent-id');
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\FileNotFoundException
     */
    public function testOpenDownloadStreamByNameShouldRequireFilenameToExist()
    {
        $this->bucket->openDownloadStream('nonexistent-filename');
    }

    public function testOpenDownloadStreamByName()
    {
        $this->bucket->uploadFromStream('filename', $this->createStream('foo'));
        $this->bucket->uploadFromStream('filename', $this->createStream('bar'));
        $this->bucket->uploadFromStream('filename', $this->createStream('baz'));

        $this->assertStreamContents('baz', $this->bucket->openDownloadStreamByName('filename'));
        $this->assertStreamContents('foo', $this->bucket->openDownloadStreamByName('filename', ['revision' => -3]));
        $this->assertStreamContents('bar', $this->bucket->openDownloadStreamByName('filename', ['revision' => -2]));
        $this->assertStreamContents('baz', $this->bucket->openDownloadStreamByName('filename', ['revision' => -1]));
        $this->assertStreamContents('foo', $this->bucket->openDownloadStreamByName('filename', ['revision' => 0]));
        $this->assertStreamContents('bar', $this->bucket->openDownloadStreamByName('filename', ['revision' => 1]));
        $this->assertStreamContents('baz', $this->bucket->openDownloadStreamByName('filename', ['revision' => 2]));
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\FileNotFoundException
     * @dataProvider provideNonexistentFilenameAndRevision
     */
    public function testOpenDownloadStreamByNameShouldRequireFilenameAndRevisionToExist($filename, $revision)
    {
        $this->bucket->uploadFromStream('filename', $this->createStream('foo'));
        $this->bucket->uploadFromStream('filename', $this->createStream('bar'));

        $this->bucket->openDownloadStream($filename, ['revision' => $revision]);
    }

    public function testOpenUploadStream()
    {
        $stream = $this->bucket->openUploadStream('filename');

        fwrite($stream, 'foobar');
        fclose($stream);

        $this->assertStreamContents('foobar', $this->bucket->openDownloadStreamByName('filename'));
    }

    /**
     * @dataProvider provideInputDataAndExpectedChunks
     */
    public function testOpenUploadStreamAndMultipleWriteOperations($input)
    {
        $stream = $this->bucket->openUploadStream('filename');
        $offset = 0;

        while ($offset < strlen($input)) {
            $expectedWriteLength = min(4096, strlen($input) - $offset);
            $writeLength = fwrite($stream, substr($input, $offset, 4096));
            $offset += $writeLength;

            $this->assertEquals($expectedWriteLength, $writeLength);
        }

        $this->assertTrue(fclose($stream));
        $this->assertStreamContents($input, $this->bucket->openDownloadStreamByName('filename'));
    }

    public function testRename()
    {
        $id = $this->bucket->uploadFromStream('a', $this->createStream('foo'));
        $this->bucket->rename($id, 'b');

        $fileDocument = $this->filesCollection->findOne(
            ['_id' => $id],
            ['projection' => ['filename' => 1, '_id' => 0]]
        );

        $this->assertSameDocument(['filename' => 'b'], $fileDocument);
        $this->assertStreamContents('foo', $this->bucket->openDownloadStreamByName('b'));
    }

    public function testRenameShouldNotRequireFileToBeModified()
    {
        $id = $this->bucket->uploadFromStream('a', $this->createStream('foo'));
        $this->bucket->rename($id, 'a');

        $fileDocument = $this->filesCollection->findOne(
            ['_id' => $id],
            ['projection' => ['filename' => 1, '_id' => 0]]
        );

        $this->assertSameDocument(['filename' => 'a'], $fileDocument);
        $this->assertStreamContents('foo', $this->bucket->openDownloadStreamByName('a'));
    }

    /**
     * @expectedException MongoDB\GridFS\Exception\FileNotFoundException
     */
    public function testRenameShouldRequireFileToExist()
    {
        $this->bucket->rename('nonexistent-id', 'b');
    }

    public function testUploadFromStream()
    {
        $options = [
            '_id' => 'custom-id',
            'chunkSizeBytes' => 2,
            'metadata' => ['foo' => 'bar'],
        ];

        $id = $this->bucket->uploadFromStream('filename', $this->createStream('foobar'), $options);

        $this->assertCollectionCount($this->filesCollection, 1);
        $this->assertCollectionCount($this->chunksCollection, 3);
        $this->assertSame('custom-id', $id);

        $fileDocument = $this->filesCollection->findOne(['_id' => $id]);

        $this->assertSameDocument(['foo' => 'bar'], $fileDocument['metadata']);
    }

    /**
     * @expectedException MongoDB\Exception\InvalidArgumentException
     * @dataProvider provideInvalidStreamValues
     */
    public function testUploadFromStreamShouldRequireSourceStream($source)
    {
        $this->bucket->uploadFromStream('filename', $source);
    }

    public function testUploadingAnEmptyFile()
    {
        $id = $this->bucket->uploadFromStream('filename', $this->createStream(''));
        $destination = $this->createStream();
        $this->bucket->downloadToStream($id, $destination);

        $this->assertStreamContents('', $destination);
        $this->assertCollectionCount($this->filesCollection, 1);
        $this->assertCollectionCount($this->chunksCollection, 0);

        $fileDocument = $this->filesCollection->findOne(
            ['_id' => $id],
            [
                'projection' => [
                    'length' => 1,
                    'md5' => 1,
                    '_id' => 0,
                ],
            ]
        );

        $expected = [
            'length' => 0,
            'md5' => 'd41d8cd98f00b204e9800998ecf8427e',
        ];

        $this->assertSameDocument($expected, $fileDocument);
    }

    public function testUploadingFirstFileCreatesIndexes()
    {
        $this->bucket->uploadFromStream('filename', $this->createStream('foo'));

        $this->assertIndexExists($this->filesCollection->getCollectionName(), 'filename_1_uploadDate_1');
        $this->assertIndexExists($this->chunksCollection->getCollectionName(), 'files_id_1_n_1', function(IndexInfo $info) {
            $this->assertTrue($info->isUnique());
        });
    }

    /**
     * Asserts that a collection with the given name does not exist on the
     * server.
     *
     * @param string $collectionName
     */
    private function assertCollectionDoesNotExist($collectionName)
    {
        $operation = new ListCollections($this->getDatabaseName());
        $collections = $operation->execute($this->getPrimaryServer());

        $foundCollection = null;

        foreach ($collections as $collection) {
            if ($collection->getName() === $collectionName) {
                $foundCollection = $collection;
                break;
            }
        }

        $this->assertNull($foundCollection, sprintf('Collection %s exists', $collectionName));
    }

    /**
     * Asserts that an index with the given name exists for the collection.
     *
     * An optional $callback may be provided, which should take an IndexInfo
     * argument as its first and only parameter. If an IndexInfo matching the
     * given name is found, it will be passed to the callback, which may perform
     * additional assertions.
     *
     * @param string   $collectionName
     * @param string   $indexName
     * @param callable $callback
     */
    private function assertIndexExists($collectionName, $indexName, $callback = null)
    {
        if ($callback !== null && ! is_callable($callback)) {
            throw new InvalidArgumentException('$callback is not a callable');
        }

        $operation = new ListIndexes($this->getDatabaseName(), $collectionName);
        $indexes = $operation->execute($this->getPrimaryServer());

        $foundIndex = null;

        foreach ($indexes as $index) {
            if ($index->getName() === $indexName) {
                $foundIndex = $index;
                break;
            }
        }

        $this->assertNotNull($foundIndex, sprintf('Index %s does not exist', $indexName));

        if ($callback !== null) {
            call_user_func($callback, $foundIndex);
        }
    }
}
