<?php
/**
 * File containing the ezcDocumentEzXmlDummyLinkConverter class.
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
 * @package Document
 * @version //autogen//
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

/**
 * Class for conversions of links, given as natural URLs into the eZXml storage
 * format, which may result in urlIds, nodeIds, or similar.
 *
 * @package Document
 * @version //autogen//
 */
class ezcDocumentEzXmlDummyLinkConverter extends ezcDocumentEzXmlLinkConverter
{
    /**
     * Get URL properties
     *
     * Return an array of the attributes, which should be set for the link.
     *
     * @param string $url
     * @return array
     */
    public function getUrlProperties( $url )
    {
        return array(
            'url_id' => 23,
        );
    }
}

?>
