<?php

namespace App\Utils\Connectors;

use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\ReadCoilsRequest;
use ModbusTcpClient\Packet\ModbusFunction\ReadInputRegistersRequest;
use ModbusTcpClient\Packet\ModbusFunction\ReadHoldingRegistersRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleRegisterRequest;
use ModbusTcpClient\Packet\ResponseFactory;
use ModbusTcpClient\Utils\Types;

/**
 * Connector base class to retrieve data via ModbusTcp
 *
 * @author Markus Schafroth
 */
class ModbusTcpConnector
{
    protected $ip;
    protected $port;
    private $modbusConnection;

    protected function __construct()
    {
        $this->modbusConnection = BinaryStreamConnection::getBuilder()
            ->setPort($this->port)
            ->setHost($this->ip)
            ->build();
    }

    /*
     * read warm water temperature via ModbusTCP
     */
    protected function readTempModbusTcp($address)
    {
        $bytes = $this->readBytesFc4ModbusTcp($address);
        $uint = Types::parseUInt16(Types::byteArrayToByte($bytes));
        $int = $uint;
        if ($uint > 65535/2) { // 65535 is the max value for a uint16
            $int = -1*(65535-$uint);
        }
        return $int/10;
    }

    /*
     * read uint16 via ModbusTCP
     */
    protected function readUint16ModbusTcp($address)
    {
        $bytes = $this->readBytesFc4ModbusTcp($address);

        return Types::parseInt16(Types::byteArrayToByte($bytes));
    }

    /*
     * read bool via ModbusTCP
     */
    protected function readBoolModbusTcp($address)
    {
        $coilVal = $this->readBytesCoilsModbusTcp($address);

        return $coilVal;
    }

    /*
     * read bytes of a single coils (FC1 function)
     */
    protected function readBytesCoilsModbusTcp($address)
    {
        $packet = new ReadCoilsRequest($address, 1, 1);
        $binaryData = $this->modbusConnection->connect()->sendAndReceive($packet);
        $response = ResponseFactory::parseResponseOrThrow($binaryData);
        $responseWithStartAddress = $response->withStartAddress($address);

        return $responseWithStartAddress[$address];
    }

    /*
     * read bytes of a single input register (FC4 function)
     */
    protected function readBytesFc4ModbusTcp($address)
    {
        $packet = new ReadInputRegistersRequest($address, 1, 1);
        $binaryData = $this->modbusConnection->connect()->sendAndReceive($packet);
        $response = ResponseFactory::parseResponseOrThrow($binaryData);
        $responseWithStartAddress = $response->withStartAddress($address);

        return $responseWithStartAddress[$address]->getBytes();
    }

    /*
     * read bytes of a single holding register (FC3 function)
     */
    protected function readBytesFc3ModbusTcp($address)
    {
        $packet = new ReadHoldingRegistersRequest($address, 1, 1);
        $binaryData = $this->modbusConnection->connect()->sendAndReceive($packet);
        $response = ResponseFactory::parseResponseOrThrow($binaryData);
        $responseWithStartAddress = $response->withStartAddress($address);

        return $responseWithStartAddress[$address]->getBytes();
    }

    /*
    * write value into a single holding register (FC6 function)
    */
    protected function writeBytesFc3ModbusTcp($address, $value)
    {
        $packet = new WriteSingleRegisterRequest($address, $value);
        $binaryData = $this->modbusConnection->connect()->sendAndReceive($packet);
        $response = ResponseFactory::parseResponseOrThrow($binaryData);

        return $response->getWord()->getInt16;
    }

    protected function toHex($dec)
    {
        $hex = strtoupper(dechex(intval($dec)));
        if ($dec < 16) {
            $hex = '0' . $hex;
        }

        return $hex;
    }
}
