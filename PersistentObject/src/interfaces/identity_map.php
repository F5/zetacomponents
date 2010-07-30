<?php
/**
 * File containing the ezcPersistentIdentityMap interface.
 *
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements.  See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership.  The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License.  You may obtain a copy of the License at
 * 
 *   http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 *
 * @package PersistentObject
 * @version //autogen//
 * @copyright Copyright (C) 2005-2010 eZ Systems AS. All rights reserved.
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */
/**
 * Identity map interface.
 *
 * An instance of a class implementing this interface is used in {@link
 * ezcPersistentSessionIdentityDecorator} to performs the internal work of
 * storing and retrieving object identities.
 * 
 * @package PersistentObject
 * @version //autogen//
 */
interface ezcPersistentIdentityMap
{
    /**
     * Records the identity of $object.
     *
     * Records the identity of $object. If an identity is already recorded for
     * this object, it is silently replaced. The user of this method must take
     * care of checking for already recorded identities of the given $object
     * itself.
     *
     * @param ezcPersistentObject $object 
     */
    public function setIdentity( $object );

    /**
     * Returns the object of $class with $id or null.
     *
     * Returns the object of $class with $id, if its identity has already been
     * recorded. Otherwise null is returned.
     * 
     * @param string $class 
     * @param mixed $id 
     * @return object($class)|null
     */
    public function getIdentity( $class, $id );

    /**
     * Removes the object of $class width $id from the map.
     *
     * Removes the identity of the object of $class with $id from the map and
     * deletes all references of it. If the identity does not exist, the call
     * is silently ignored.
     * 
     * @param string $class 
     * @param mixed $id 
     */
    public function removeIdentity( $class, $id );

    /**
     * Records a set of $relatedObjects to $sourceObject.
     *
     * Records the given set of $relatedObjects for $sourceObject.
     * $relationName is the optional name of the relation, which must be
     * provided, if multiple relations from $sourceObject to the class of the
     * objects in $relatedObjects exist.
     *
     * In case a set of related objects has already been recorded for
     * $sourceObject and the class of the objects in $relatedObjects (and
     * optionally $relationName), the existing set is silently replaced and all
     * references to it are removed.
     *
     * If for any object in $relatedObjects no identity is recorded, yet, it
     * will be recorded. Otherwise, the object will be replaced by its existing
     * identity in the set. Except for if the $replaceIdentities parameter is
     * set to true: In this case a new identity will be recorded for every
     * object in $relatedObjects, replacing potentially existing ones silently.
     *
     * If the given array of $relatedObjects is inconsistent (any contained
     * object is not of $relatedClass), an {@link
     * ezcPersistentIdentityRelatedObjectsInconsistentException} is thrown.
     *
     * To avoid a call to {@link getRelatedObjects()} after this method has
     * been called, the recorded set of related objects (including potentially
     * replaced identities) is returned.
     * 
     * @param ezcPersistentObject $sourceObject
     * @param array(ezcPersistentObject) $relatedObjects 
     * @param string $relatedClass 
     * @param string $relationName 
     * @param bool $replaceIdentities
     *
     * @return array(mixed=>object($relatedClass))
     *
     * @throws ezcPersistentIdentityRelatedObjectsInconsistentException
     *         if an object in $relatedObjects is not of $relatedClass.
     *
     */
    public function setRelatedObjects( $sourceObject, array $relatedObjects, $relatedClass, $relationName = null, $replaceIdentities = false );

    /**
     * Records a named set of $relatedObjects for $sourceObject.
     *
     * Records the given array of $relatedObjects with as a "named related
     * object sub-set" for $sourceObject, using $setName. A named "named
     * related object sub-set" contains only objects related to $sourceObject,
     * but not necessarily all such objects of a certain class. Such a set is
     * the result of {@link ezcPersistentSessionIdentityDecorator::find()} with
     * a find query generated by {@link
     * ezcPersistentSessionIdentityDecorator::createFindQueryWithRelations()}
     * and manipulated using a WHERE clause.
     *
     * In case a named set of related objects with $setName has already been
     * recorded for $sourceObject, this set is silently replaced.
     *
     * If for any of the objects in $relatedObjects no identity is recorded,
     * yet, it will be recorded. Otherwise, the object will be replaced by its
     * existing identity in the set. Except for if $replaceIdentities is set to
     * true: In this case a new identity will be recorded for every object in
     * $relatedObjects.
     *
     * The method returns the created set of related objects to avoid another
     * call to {@link getRelatedObjectSet()} by the using objct.
     * 
     * @param ezcPersistentObject $sourceObject
     * @param array(ezcPersistentObject) $relatedObjects 
     * @param string $setName 
     * @param bool $replaceIdentities
     *
     * @return array(ezcPersistentObject)
     *
     * @throws ezcPersistentIdentityRelatedObjectsInconsistentException
     *         if an object in $relatedObjects is not of $relatedClass.
     */
    public function setRelatedObjectSet( $sourceObject, array $relatedObjects, $setName, $replaceIdentities = false );

    /**
     * Appends a new $relatedObject to a related object set of $sourceObject.
     *
     * Appends the given $relatedObject to the set of related objects for
     * $sourceObject with the class of $relatedObject and optionally
     * $relationName.
     *
     * In case that no set of related objects with the specific class has been
     * recorded for $object, yet, the call is ignored and related objects are
     * newly fetched whenever {@link getRelatedObjects()} is called.
     *
     * Note: All named related object sub-sets for $relatedObject are
     * automatically invalidated by a call to the method. The identity map can
     * not determine, to which named related object sub-set the $relatedObject
     * might be added.
     *
     * @param ezcPersistentObject $sourceObject 
     * @param ezcPersistentObject $relatedObject 
     * @param string $relationName
     *
     * @throws ezcPersistentRelationNotFoundException
     *         if no relation from $sourceObject to $relatedObject is defined.
     * @throws ezcPersistentIdentityMissingException
     *         if no identity has been recorded for $sourceObject or
     *         $relatedObject, yet.
     * @throws ezcPersistentIdentityRelatedObjectAlreadyExistsException
     *         if the given $relatedObject is already part of the set of
     *         related objects it should be added to.
     */
    public function addRelatedObject( $sourceObject, $relatedObject, $relationName = null );

    /**
     * Removes a $relatedObject from the sets of related objects of $sourceObject.
     *
     * Removes the $relatedObject from all recorded sets of related objects
     * (named and unnamed) for $sourceObject. This method (in contrast to
     * {@link addRelatedObject()}) does not invalidate named related object
     * sets, but simply removes the $relatedObject from them.
     * 
     * @param ezcPersistentObject $sourceObject 
     * @param ezcPersistentObject $relatedObject 
     * @param string $relationName
     *
     * @throws ezcPersistentIdentityMissingException
     *         if no identity for $sourceObject has been recorded, yet.
     */
    public function removeRelatedObject( $sourceObject, $relatedObject, $relationName = null );

    /**
     * Returns the set of related objects of $relatedClass for $sourceObject.
     *
     * Returns the set of related objects of $relatedClass for $sourceObject.
     * This might also be an empty set (empty array). In case no related
     * objects are recorded, yet, null is returned.
     * 
     * @param ezcPersistentObject $sourceObject 
     * @param string $relatedClass 
     * @param string $relationName
     *
     * @return array(object($relatedClass))|null
     *
     * @throws ezcPersistentRelationNotFoundException
     *         if not relation between the class of $sourceObject and
     *         $relatedClass (with optionally $relationName) is defined.
     */
    public function getRelatedObjects( $sourceObject, $relatedClass, $relationName = null );

    /**
     * Returns the named set of related objects for $sourceObject with $setName.
     *
     * Returns the named set of related objects for $sourceObject identified by
     * $setName. This might also be an empty set (empty array). In case no
     * related objects with this name are recorded, yet, null is returned.
     * 
     * @param ezcPersistentObject $sourceObject 
     * @param string $setName 
     * @return array(object($relatedClass))|null
     */
    public function getRelatedObjectSet( $sourceObject, $setName );

    /**
     * Resets the complete identity map.
     *
     * Removes all stored identities from the map and resets it into its
     * initial state.
     */
    public function reset();
}

?>
