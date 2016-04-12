<?php

namespace Members\Tool;

use Members\Auth\Adapter;
use Members\Model\Restriction;
use Members\Model\Configuration;

use Pimcore\Model\Object;

class Tool {

    const STATE_LOGGED_IN = 'loggedIn';
    const STATE_NOT_LOGGED_IN = 'notLoggedIn';

    const SECTION_ALLOWED = 'allowed';
    const SECTION_NOT_ALLOWED = 'notAllowed';

    public static function generateNavCacheKey()
    {
        $identity = self::getIdentity();

        if (isset($_COOKIE['pimcore_admin_sid']))
        {
            return md5('pimcore_admin');
        }

        if( $identity instanceof Object\Member)
        {
            $allowedGroups = $identity->getGroups();

            if( !empty( $allowedGroups ))
            {
                $m = implode('-', $allowedGroups );
                return md5( $m );
            }

            return TRUE;
        }

        return TRUE;

    }

    public static function getDocumentRestrictedGroups( $document )
    {
        $restriction = self::getRestrictionObject( $document, 'page', true );

        $groups = array();

        if( $restriction !== FALSE && is_array( $restriction->relatedGroups))
        {
            $groups = $restriction->relatedGroups;
        }
        else
        {
            $groups[] = 'default';
        }

        return $groups;
    }

    /**
     * @param \Pimcore\Model\Document\Page $document
     *
     * @return array (state, section)
     */
    public static function isRestrictedDocument( \Pimcore\Model\Document\Page $document )
    {
        $status = array('state' => NULL, 'section' => NULL);

        $restriction = self::getRestrictionObject( $document, 'page' );

        if( $restriction === FALSE)
        {
            $status['state'] = self::STATE_NOT_LOGGED_IN;
            $status['section'] = self::SECTION_ALLOWED;
            return $status;
        }

        $identity = self::getIdentity();

        $restrictionRelatedGroups = $restriction->getRelatedGroups();

        if( $identity instanceof Object\Member )
        {
            $status['state'] = self::STATE_LOGGED_IN;
            $status['section'] = self::SECTION_NOT_ALLOWED;

            if( !empty( $restrictionRelatedGroups ) && $identity instanceof Object\Member)
            {
                $allowedGroups = $identity->getGroups();
                $intersectResult = array_intersect($restrictionRelatedGroups, $allowedGroups);

                if( count($intersectResult) > 0 )
                {
                    $status['section'] = self::SECTION_ALLOWED;
                }

            }

        }

        return $status;
    }

    public static function getCurrentUserAllowedGroups() {

        $identity = \Zend_Auth::getInstance()->getIdentity();

        if( $identity instanceof \Pimcore\Model\Object\Member )
        {
            $allowedGroups = $identity->getGroups();

            return $allowedGroups;

        }

    }

    private static function getRestrictionObject( $document, $cType = 'page', $ignoreLoggedIn = FALSE )
    {
        $restriction = FALSE;

        //@fixme! bad?
        if (isset($_COOKIE['pimcore_admin_sid']) && $ignoreLoggedIn == FALSE)
        {
            return FALSE;
        }

        try
        {
            $restriction = Restriction::getByTargetId( $document->getId(), $cType );
        }
        catch(\Exception $e)
        {
        }

        if($restriction === FALSE)
        {
            $docParentIds = $document->getDao()->getParentIds();
            $nextHigherRestriction = Restriction::findNextInherited( $document->getId(), $docParentIds, 'page' );

            if( $nextHigherRestriction->getId() !== null )
            {
                $restriction = $nextHigherRestriction;
            }
            else
            {
                $restriction = FALSE;
            }

        }

        return $restriction;

    }

    private static function getIdentity($forceFromStorage = false)
    {
        $identity = \Zend_Auth::getInstance()->getIdentity();

        if (!$identity && isset($_SERVER['PHP_AUTH_PW']))
        {
            $username = $_SERVER['PHP_AUTH_USER'];
            $password = $_SERVER['PHP_AUTH_PW'];

            $identity = self::getServerIdentity( $username, $password );
        }

        if ($identity && $forceFromStorage)
        {
            $identity = Object\Member::getById($identity->getId());
        }

        if( $identity instanceof \Pimcore\Model\Object\Member )
        {
            return $identity;
        }

        return FALSE;

    }

    private static function getServerIdentity( $username, $password )
    {
        $auth = \Zend_Auth::getInstance();

        $adapterSettings = array(

            'identityClassname'     =>  Configuration::get('auth.adapter.identityClassname'),
            'identityColumn'        =>  Configuration::get('auth.adapter.identityColumn'),
            'credentialColumn'      =>  Configuration::get('auth.adapter.credentialColumn'),
            'objectPath'            =>  Configuration::get('auth.adapter.objectPath')

        );

        $adapter = new Adapter( $adapterSettings );
        $adapter
            ->setIdentity($username)
            ->setCredential($password);
        $result = $auth->authenticate($adapter);

        if ($result->isValid())
        {
            return \Zend_Auth::getInstance()->getIdentity();
        }

        return FALSE;

    }
}